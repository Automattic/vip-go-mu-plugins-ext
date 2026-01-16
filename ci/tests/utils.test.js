const { describe, it } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const {
    compareVersions,
    isBeta,
    parseVersionString,
    fetchAllTags
} = require( '../utils' );

describe( 'compareVersions()', () => {
    it( 'should return 1 if the currentVersion is older than the latestVersion', () => {
        const expected = 1;
        const versionsToCompare = {
            // latest version => current version key-value pairing
            '11.7': '11.7-beta3',
            '11.7-beta4': '11.7-beta3',
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
