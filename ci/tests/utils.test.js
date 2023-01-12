const { describe, it } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const {
    compareVersions,
    isBeta
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
