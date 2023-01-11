#!/usr/bin/env node

/**
 * Compares two versions against each other
 * @param {*} a latestVersion Latest version in the plugin repo
 * @param {*} b currentVersion Current version in the vip-go-mu-plugins-ext repo
 * @returns int -1, 1, 0
 */
function compareVersions(a, b) {
    if (a === b) {
        return 0;
    }
    const aParts = parseVersion(a)
    const bParts = parseVersion(b)

    const majorCmpr = aParts[0] - bParts[0];
    if (majorCmpr !== 0) {
        return majorCmpr;
    }

    const minorCmpr = aParts[1] - bParts[1];
    if (minorCmpr !== 0) {
        return minorCmpr;
    }

    if ( isBeta( a ) && isBeta( b ) ) {
        return stripBetaFromString( aParts[2] ) - stripBetaFromString( bParts[2] );
    } else if ( isBeta( b ) && ! isBeta( a ) ) {
        return 1;
    } else if ( isBeta( a) && ! isBeta( b ) ) {
        return -1;
    }

    if (aParts.length === 2) {
        return -1;
    }
    if (bParts.length === 2) {
        return 1;
    }

    return aParts[2] - bParts[2];
}

function parseVersion(version) {
    return version.split(/[.-]/).map(part => isNaN(Number(part)) ? part : Number(part));
}

function isBeta(version) {
    return typeof version === 'string' && version.indexOf( 'beta') !== -1;
}

function stripBetaFromString( string ) {
    return string.replace( 'beta', '' );
}

module.exports = {
    compareVersions,
    isBeta
};
