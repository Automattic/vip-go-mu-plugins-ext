#!/bin/sh

set -ex

basedir="${0%/*}/.."

version=latest
clientCodePath=demo

while getopts v:p:c: flag
do
    case "${flag}" in
        v) version=${OPTARG};;
        p) pluginPath=${OPTARG};;
        c) clientCodePath=${OPTARG};;
        *) echo "WARNING: Unexpected option ${flag}";;
    esac
done

if [ -z "${version}" ]; then
    version=${WORDPRESS_VERSION:-latest}
fi

if [ "${version}" = "latest" ]; then
    WPVER="$(wget https://github.com/Automattic/vip-container-images/raw/refs/heads/master/wordpress/versions.json -O - | jq -r '[.[] | select(.prerelease == false)] | max_by(.tag) | .tag')"
else
    WPVER="$(wget https://github.com/Automattic/vip-container-images/raw/refs/heads/master/wordpress/versions.json -O - | jq -r --arg ref_value "${version}" '.[] | select(.ref == $ref_value) | .tag')"
fi

if [ -z "${WPVER}" ]; then
    WPVER=trunk
fi

# Destroy existing test site
vip dev-env destroy --slug=e2e-sb-test-site || true

# Create and run test site
vip --slug=e2e-sb-test-site dev-env create --title="E2E Testing site" --mailpit false --wordpress="${WPVER}" --multisite=false --php 8.2 --xdebug false --phpmyadmin false --elasticsearch false < /dev/null
vip dev-env start --slug e2e-sb-test-site --skip-wp-versions-check
vip dev-env shell --root --slug e2e-sb-test-site -- chown -R www-data:www-data /wp/wp-content
if [ "${WPVER}" = 'trunk' ]; then
    vip dev-env exec --slug e2e-sb-test-site --quiet -- wp core update --force --version="${version}"
    vip dev-env exec --slug e2e-sb-test-site --quiet -- wp core update-db
fi
vip dev-env exec --slug e2e-sb-test-site --quiet -- wp rewrite structure '/%postname%/'

# Change admin password to "password"
vip dev-env exec --slug e2e-sb-test-site --quiet -- wp user update vipgo --user_pass=password
vip dev-env exec --slug e2e-sb-test-site --quiet -- wp user create sbcontributor test-contributor@example.local --user_pass=password --role=contributor
vip dev-env exec --slug e2e-sb-test-site --quiet -- wp user create sbeditor test-editor@example.local --user_pass=password --role=editor
vip dev-env exec --slug e2e-sb-test-site --quiet -- wp user create sbadmin test-admin@example.local --user_pass=password --role=administrator
vip dev-env exec --slug e2e-sb-test-site --quiet -- wp user create sbinactiveadmin test-inactiveadmin@example.local --user_pass=password --role=administrator
vip dev-env exec --slug e2e-sb-test-site --quiet -- wp user create sbinactivecontributor test-inactivecontributor@example.local --user_pass=password --role=contributor

# set the admin as inactive
vip dev-env exec --slug e2e-sb-test-site --quiet -- wp option set wpvip_last_seen_release_date_timestamp 1708528298
vip dev-env exec --slug e2e-sb-test-site --quiet -- wp user update sbinactiveadmin --user_registered='2023-01-15 10:00:00'
vip dev-env exec --slug e2e-sb-test-site --quiet -- wp user meta set sbinactiveadmin wpvip_last_seen 1699459200
vip dev-env exec --slug e2e-sb-test-site --quiet -- wp user meta set sbinactiveadmin wpvip_last_seen_ignore_inactivity_check_until 1599459200

# set the contributor as inactive
vip dev-env exec --slug e2e-sb-test-site --quiet -- wp option set wpvip_last_seen_release_date_timestamp 1708528298
vip dev-env exec --slug e2e-sb-test-site --quiet -- wp user update sbinactivecontributor --user_registered='2023-01-15 10:00:00'
vip dev-env exec --slug e2e-sb-test-site --quiet -- wp user meta set sbinactivecontributor wpvip_last_seen 1699459200
vip dev-env exec --slug e2e-sb-test-site --quiet -- wp user meta set sbinactivecontributor wpvip_last_seen_ignore_inactivity_check_until 1599459200

# Enable 2FA locally and skip vipgo user
vip dev-env shell --slug e2e-sb-test-site -- bash -lc "cat <<'EOF' >> /wp/wp-content/plugins/vip-security-boost/vip-security-boost.php
remove_filter( 'wpcom_vip_is_two_factor_forced', '__return_false' );
add_filter( 'wpcom_vip_is_two_factor_local_testing', '__return_true' );
add_filter('wpcom_vip_is_two_factor_forced', function ( \$forced ) {
	// if user is vipgo skip forcing 2FA, to help playwright tests
	if ( is_user_logged_in() ) {
		if ( wp_get_current_user()->user_login === 'vipgo' ) {
			return false;
		}
	}
	return \$forced;
} );
EOF"
