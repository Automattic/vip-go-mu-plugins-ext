import { type Response, expect, test } from '@playwright/test';

import { getSecurityBoostConfigHeaders } from '../lib/security-boost-config-helper';
test.describe( 'Generic Checks', () => {
	test( 'Page contains closing html tag and no wp_die() message', async ( { page, baseURL } ) => {
		expect( baseURL ).toBeDefined();
		const response = await page.goto( baseURL! ) as Response;
		expect.soft( response.status() ).toBeLessThan( 500 );
		await expect( page.locator( '.wp-die-message' ) ).toHaveCount( 0 );
		const html = await page.content();
		expect( html ).toContain( '</html>' );
	} );

	test( 'REST API smoke test', async ( { request } ) => {
		const response = await request.get( './wp-json/' );
		expect( response.status() ).toBe( 200 );
		const data: unknown = await response.json();
		expect( typeof data ).toBe( 'object' );
		expect.soft( data ).toHaveProperty( 'name' );
		expect.soft( data ).toHaveProperty( 'description' );
		expect.soft( data ).toHaveProperty( 'url' );
		expect.soft( data ).toHaveProperty( 'routes' );
	} );

	test( 'XML RPC works when allowed', async ( { request } ) => {
		const xmlPayload = `<methodCall>
			<methodName>wp.getUsersBlogs</methodName>
				<params>
				<param><value><string>vipgo</string></value></param>
				<param><value><string>password</string></value></param>
				</params>
			</methodCall>`;

		const response = await request.post( './xmlrpc.php', {
			headers: {
				'Content-Type': 'text/xml',
				...getSecurityBoostConfigHeaders( {
					module_configs: {
						'xml-rpc': {
							mode: 'ALLOW',
						},
					},
				} ),
			},

			data: xmlPayload,
		} );

		expect( response.status() ).toBe( 200 );
		const responseText = await response.text();

		expect( responseText ).toContain( '<methodResponse>' );
		expect( responseText ).not.toContain( '<fault>' );
		expect( responseText ).toContain( '<member><name>blogName</name><value><string>E2E Testing site</string></value></member>' );
	} );
	test( 'XML RPC Disabled', async ( { request } ) => {
		const xmlPayload = '<?xml version="1.0"?><methodCall><methodName>demo.sayHello</methodName><params/></methodCall>';

		const response = await request.post( './xmlrpc.php', {
			headers: {
				'Content-Type': 'text/xml',
			},
			data: xmlPayload,
		} );

		expect( response.status() ).toBe( 403 );
		const responseText = await response.text();
		expect( responseText ).toContain( 'Access to XML-RPC is disabled on this site.' );
	} );

	test( 'XML RPC blocked when restricted to app passwords', async ( { request } ) => {
		const xmlPayload = `<methodCall>
			<methodName>wp.getUsersBlogs</methodName>
				<params>
				<param><value><string>vipgo</string></value></param>
				<param><value><string>password</string></value></param>
				</params>
			</methodCall>`;

		const response = await request.post( './xmlrpc.php', {
			headers: {
				'Content-Type': 'text/xml',
				...getSecurityBoostConfigHeaders( {
					module_configs: {
						'xml-rpc': {
							mode: 'RESTRICT',
						},
					},
				} ),
			},

			data: xmlPayload,
		} );

		expect( response.status() ).toBe( 200 );
		const responseText = await response.text();

		expect( responseText ).toContain( '<methodResponse>' );
		expect( responseText ).toContain( '<fault>' );
		expect( responseText ).toContain( '<string>Incorrect username or password.</string>' );
	} );
} );
