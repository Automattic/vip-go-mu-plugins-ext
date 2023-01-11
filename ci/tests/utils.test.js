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
        for ( var latestVersion in versionsToCompare ) {
            const result = compareVersions( latestVersion, versionsToCompare[ latestVersion ] );
            expect( result ).toEqual( expected );
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
        for ( var latestVersion in versionsToCompare ) {
            const result = compareVersions( latestVersion, versionsToCompare[ latestVersion ] );
            expect( result ).toEqual( expected );
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
        for ( var latestVersion in versionsToCompare ) {
            const result = compareVersions( latestVersion, versionsToCompare[ latestVersion ] );
            expect( result ).toEqual( expected );
        }
    });
});

describe( 'isBeta()', () => {
    it( 'should return true if it has beta in the string', () => {
        expect( isBeta( '11.7-beta3' ) ).toEqual( true );
    });
    it( 'should return false if it does not have beta in the string', () => {
        expect( isBeta( '11.7.1' ) ).toEqual( false );
    });
    it( 'should return false if it is not a string', () => {
        expect( isBeta( 11.7 ) ).toEqual( false );
    });
});
