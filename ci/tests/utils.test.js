const { describe, it } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const { execFileSync } = require( 'node:child_process' );
const fs = require( 'node:fs' );
const os = require( 'node:os' );
const path = require( 'node:path' );
const {
    compareVersions,
    isBeta,
    parseVersionString,
    resolveReleaseZipFileName,
    fetchAllTags,
    discoverPluginVersions
} = require( '../utils' );

describe( 'compareVersions()', () => {
    it( 'should return 1 if the currentVersion is older than the latestVersion', () => {
        const expected = 1;
        const versionsToCompare = {
            // latest version => current version key-value pairing
            '11.7': '11.7-beta3',
            '11.7-beta4': '11.7-beta3',
            '1.0.0-beta.2': '1.0.0-beta.1',
            '1.0.0-beta.1': '1.0.0-alpha.2',
            '11.7.1': '11.7',
            '11.7.4': '11.7.3',
            '3.6.1': '3.6.0',
        };
        for ( const latestVersion in versionsToCompare ) {
            const result = compareVersions( latestVersion, versionsToCompare[ latestVersion ] );
            assert.strictEqual( result, expected );
        }
    });

    it( 'should return 0 if the currentVersion is same as latestVersion', () => {
        const expected = 0;
        const versionsToCompare = {
            // latest version => current version key-value pairing
            '11.7-beta3': '11.7-beta3',
            '1.0.0-beta.1': '1.0.0-beta.1',
            '11.7.1': '11.7.1',
            '3.6.0': '3.6.0',
        };
        for ( const latestVersion in versionsToCompare ) {
            const result = compareVersions( latestVersion, versionsToCompare[ latestVersion ] );
            assert.strictEqual( result, expected );
        }
    });

    it( 'should return -1 if for some reason, the currentVersion is newer than latestVersion', () => {
        const expected = -1;
        const versionsToCompare = {
            // latest version => current version key-value pairing
            '11.7-beta2': '11.7-beta3',
            '1.0.0-alpha.2': '1.0.0-beta.1',
            '11.7': '11.7.1',
            '3.6.0': '3.6.1',
        };
        for ( const latestVersion in versionsToCompare ) {
            const result = compareVersions( latestVersion, versionsToCompare[ latestVersion ] );
            assert.strictEqual( result, expected );
        }
    });
});

describe( 'isBeta()', () => {
    it( 'should return true if it has beta in the string', () => {
        assert.strictEqual( isBeta( '11.7-beta3' ), true );
        assert.strictEqual( isBeta( '1.0.0-beta.1' ), true );
    });
    it( 'should return false if it does not have beta in the string', () => {
        assert.strictEqual( isBeta( '11.7.1' ), false );
    });
    it( 'should return false if it is not a string', () => {
        assert.strictEqual( isBeta( 11.7 ), false );
    });
});

describe( 'parseVersionString()', () => {
    it( 'should parse standard semver versions', () => {
        const version = parseVersionString( '12.8.2' );
        assert.strictEqual( version.major, 12 );
        assert.strictEqual( version.minor, 8 );
        assert.strictEqual( version.patch, 2 );
        assert.strictEqual( version.beta, null );
        assert.strictEqual( version.minorKey, '12.8' );
        assert.strictEqual( version.raw, '12.8.2' );
    });

    it( 'should parse versions without patch', () => {
        const version = parseVersionString( '13.0' );
        assert.strictEqual( version.major, 13 );
        assert.strictEqual( version.minor, 0 );
        assert.strictEqual( version.patch, null );
        assert.strictEqual( version.beta, null );
        assert.strictEqual( version.minorKey, '13.0' );
        assert.strictEqual( version.raw, '13.0' );
    });

    it( 'should parse beta versions with number', () => {
        const version = parseVersionString( '13.0-beta1' );
        assert.strictEqual( version.major, 13 );
        assert.strictEqual( version.minor, 0 );
        assert.strictEqual( version.patch, null );
        assert.strictEqual( version.beta, 1 );
        assert.strictEqual( version.minorKey, '13.0' );
        assert.strictEqual( version.raw, '13.0-beta1' );
    });

    it( 'should parse beta versions without number', () => {
        const version = parseVersionString( '13.0-beta' );
        assert.strictEqual( version.major, 13 );
        assert.strictEqual( version.minor, 0 );
        assert.strictEqual( version.patch, null );
        assert.strictEqual( version.beta, 1 );
        assert.strictEqual( version.minorKey, '13.0' );
        assert.strictEqual( version.raw, '13.0-beta' );
    });

    it( 'should parse beta versions with patch', () => {
        const version = parseVersionString( '12.8.1-beta3' );
        assert.strictEqual( version.major, 12 );
        assert.strictEqual( version.minor, 8 );
        assert.strictEqual( version.patch, 1 );
        assert.strictEqual( version.beta, 3 );
        assert.strictEqual( version.minorKey, '12.8' );
        assert.strictEqual( version.raw, '12.8.1-beta3' );
    });

    it( 'should parse SemVer alpha and beta prereleases', () => {
        const alpha = parseVersionString( '1.0.0-alpha.2' );
        assert.strictEqual( alpha.alpha, 2 );
        assert.strictEqual( alpha.beta, null );
        assert.deepStrictEqual( alpha.prerelease, { channel: 'alpha', number: 2 } );

        const beta = parseVersionString( '1.0.0-beta.1' );
        assert.strictEqual( beta.alpha, null );
        assert.strictEqual( beta.beta, 1 );
        assert.deepStrictEqual( beta.prerelease, { channel: 'beta', number: 1 } );
        assert.strictEqual( beta.minorKey, '1.0' );
    });

    it( 'should return null for invalid version strings', () => {
        assert.strictEqual( parseVersionString( 'invalid' ), null );
        assert.strictEqual( parseVersionString( 'v1.2.3' ), null );
        assert.strictEqual( parseVersionString( '1.2.3.4' ), null );
        assert.strictEqual( parseVersionString( '1.2-alpha1' ), null );
    });

    it( 'should have working compare method', () => {
        const v1 = parseVersionString( '12.8.1' );
        const v2 = parseVersionString( '12.8.2' );
        const v3 = parseVersionString( '12.9.0' );
        
        assert.strictEqual( v1.compare( v2 ), -1 ); // v1 < v2
        assert.strictEqual( v2.compare( v1 ), 1 );  // v2 > v1
        assert.strictEqual( v1.compare( v1 ), 0 );  // v1 = v1
        assert.strictEqual( v2.compare( v3 ), -1 ); // v2 < v3
    });
});

describe( 'resolveReleaseZipFileName()', () => {
    it( 'should replace the version placeholder', () => {
        assert.strictEqual( resolveReleaseZipFileName( 'shareadraft-{version}-vip', '2.0.0' ), 'shareadraft-2.0.0-vip' );
    });

    it( 'should leave a name without the placeholder unchanged', () => {
        assert.strictEqual( resolveReleaseZipFileName( 'safe-publish', '1.4.1' ), 'safe-publish' );
    });
});

describe( 'discoverPluginVersions()', () => {
    // Exercise the real git tag-discovery path against disposable local repositories.
    function discoverVersions(tags, overrides = {}) {
        const repo = fs.mkdtempSync(path.join(os.tmpdir(), 'ext-stable-versions-'));
        const git = args => execFileSync('git', args, { cwd: repo, stdio: 'pipe' });

        try {
            git(['init']);
            git(['-c', 'user.name=Version Test', '-c', 'user.email=test@example.com',
                '-c', 'commit.gpgsign=false', 'commit', '--allow-empty', '-m', 'Tag fixture']);
            for (const tag of tags) {
                git(['tag', tag]);
            }

            return discoverPluginVersions('fixture', {
                repo,
                versionPrefix: 'v',
                lowestVersion: '1.8',
                skip: [],
                ignore: [],
                ...overrides
            });
        } finally {
            fs.rmSync(repo, { recursive: true, force: true });
        }
    }

    it('does not add a directory for a prerelease-only minor or major', () => {
        assert.deepStrictEqual(discoverVersions([
            'v1.8.0', 'v1.9.0-alpha.1', 'v1.9.0-beta.2', 'v2.0.0-alpha.1'
        ]), { '1.8': '1.8.0' });
    });

    it('keeps the latest stable patch when a newer patch has prereleases', () => {
        assert.deepStrictEqual(discoverVersions([
            'v1.8.0', 'v1.8.1', 'v1.8.2-alpha.1', 'v1.8.2-beta.1'
        ]), { '1.8': '1.8.1' });
    });

    it('returns no updates when every tag is a prerelease', () => {
        assert.deepStrictEqual(discoverVersions([
            'v1.9.0-alpha', 'v1.9.0-alpha.1', 'v1.9.0-beta', 'v1.9.0-beta.1',
            'v1.9-beta1', 'v1.9.0-rc.1', 'v1.9.0-dev.1'
        ]), {});
    });

    it('selects a stable release after its alpha and beta tags', () => {
        assert.deepStrictEqual(discoverVersions([
            'v1.9.0-alpha.1', 'v1.9.0-beta.2', 'v1.9.0', 'v1.9.1-alpha.1'
        ]), { '1.9': '1.9.0' });
    });

    it('preserves lowestVersion, skip and ignore filtering for stable releases', () => {
        assert.deepStrictEqual(discoverVersions([
            'v1.7.9', 'v1.8.0', 'v1.9.0', 'v1.10.0', 'v1.11.0', 'v1.12.0-beta.1'
        ], { skip: ['1.9'], ignore: ['1.10'] }), {
            '1.8': '1.8.0', '1.11': '1.11.0'
        });
    });

    it('also skips legacy beta tags in repositories without a version prefix', () => {
        assert.deepStrictEqual(discoverVersions([
            '12.8', '12.8.2', '12.8.3-beta1', '12.9-beta1', '13.0-beta'
        ], { versionPrefix: '', lowestVersion: '12.8' }), { '12.8': '12.8.2' });
    });
});
