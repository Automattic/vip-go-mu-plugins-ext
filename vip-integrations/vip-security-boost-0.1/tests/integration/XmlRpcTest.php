<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;

$client = new Client([
	'base_uri'    => 'http://vip-security-boost.vipdev.lndo.site',
	'http_errors' => false,
]);

class XmlRpcTest extends TestCase {

	/**
	 * Test that accessing XML-RPC endpoint returns 403 Forbidden when disabled.
	 *
	 * @return void
	 */
	public function testXmlRpcReturns403WhenDisabled(): void {
		global $client;
		$url = '/xmlrpc.php';

		$xml_payload = <<<XML
		<?xml version="1.0"?>
			<methodCall>
				<methodName>system.listMethods</methodName>
				<params></params>
			</methodCall>
		XML;

		$response = $client->request('POST', $url, [
			'headers' => $this->build_request_headers( 'DISABLE' ),
			'body'    => $xml_payload,
		]);

		$this->assertEquals(
			403,
			$response->getStatusCode(),
			"Expected HTTP status code 403 Forbidden, but received {$response->getStatusCode()}."
		);
	}

	/**
	 * Test that accessing XML-RPC endpoint returns 200 OK when module is disabled.
	 *
	 * @return void
	 */
	public function testXmlRpcReturns200WhenModuleDisabled(): void {
		global $client;
		$url = '/xmlrpc.php';

		$xml_payload = <<<XML
		<?xml version="1.0"?>
		<methodCall>
			<methodName>system.listMethods</methodName>
			<params></params>
		</methodCall>
		XML;

		$response = $client->request('POST', $url, [
			'headers' => array(
				[
					'X-Integration-Test'         => 'true',
					'X-Integration-Test-Configs' => $this->encode_config_header(
						array(
							'enabled_modules' => [],
						)
					),
				],
			),
			'body'    => $xml_payload,
		]);

		$this->assertEquals(
			200,
			$response->getStatusCode(),
			"Expected HTTP status code 200 OK, but received {$response->getStatusCode()}."
		);
	}

	/**
	 * Test that login/password fails when mode is RESTRICT
	 *
	 * @return void
	 */
	public function testXmlRpcAuthFailsWithUsernameAndPasswordWhenModeIsRestrict(): void {
		global $client;
		$url      = '/xmlrpc.php';
		$username = 'vipgo';
		$password = 'password';

		$xml_payload = <<<XML
		<?xml version="1.0"?>
		<methodCall>
			<methodName>wp.getUsersBlogs</methodName>
			<params>
				<param><value><string>{$username}</string></value></param>
				<param><value><string>{$password}</string></value></param>
			</params>
		</methodCall>
		XML;

		$response = $client->request('POST', $url, [
			'auth'    => [ $username, $password ],
			'headers' => $this->build_request_headers( 'RESTRICT' ),
			'body'    => $xml_payload,
		]);

		$this->assertXmlStringEqualsXmlString(
			<<<XML
				<methodResponse>
					<fault>
							<value>
									<struct>
											<member>
													<name>faultCode</name>
													<value><int>403</int></value>
											</member>
											<member>
													<name>faultString</name>
													<value><string>Incorrect username or password.</string></value>
											</member>
									</struct>
							</value>
					</fault>
			</methodResponse>
			XML,
			$response->getBody()->getContents()
		);
	}

	public function testXmlRpcAuthWorksWithEmailAndPasswordWhenModuleIsDisabled(): void {
		global $client;
		$url      = '/xmlrpc.php';
		$username = 'vipgo';
		$password = 'password';

		$xml_payload = <<<XML
		<?xml version="1.0"?>
		<methodCall>
			<methodName>wp.getUsersBlogs</methodName>
			<params>
				<param><value><string>{$username}</string></value></param>
				<param><value><string>{$password}</string></value></param>
			</params>
		</methodCall>
		XML;

		$response = $client->request('POST', $url, [
			'headers' => array(
				[
					'X-Integration-Test'         => 'true',
					'X-Integration-Test-Configs' => $this->encode_config_header(
						array(
							'enabled_modules' => [],
						)
					),
				],
			),
			'body'    => $xml_payload,
		]);

		$this->assertXmlStringEqualsXmlString(
			<<<XML
				<methodResponse>
						<params>
								<param>
										<value>
												<array>
														<data>
																<value>
																		<struct>
																				<member><name>isAdmin</name><value><boolean>1</boolean></value></member>
																				<member><name>isPrimary</name><value><boolean>1</boolean></value></member>
																				<member><name>url</name><value><string>http://vip-security-boost.vipdev.lndo.site/</string></value></member>
																				<member><name>blogid</name><value><string>1</string></value></member>
																				<member><name>blogName</name><value><string>VIP Security Boost</string></value></member>
																				<member><name>xmlrpc</name><value><string>http://vip-security-boost.vipdev.lndo.site/xmlrpc.php</string></value></member>
																		</struct>
																</value>
														</data>
												</array>    
										</value>
								</param>
						</params>
				</methodResponse>
			XML,
			$response->getBody()->getContents()
		);
	}

	/**
	 * Test that application password login works when mode is RESTRICT
	 *
	 * @return void
	 */
	public function testXmlRpcAuthSucceedsWithApplicationPasswordWhenModeIsRestrict(): void {
		global $client;
		$url                  = '/xmlrpc.php';
		$username             = 'vipgo';
		$application_password = $this->generate_application_password();

		$this->assertNotEmpty( $application_password, 'Application password should not be empty.' );

		$xml_payload = <<<XML
		<?xml version="1.0"?>
		<methodCall>
			<methodName>wp.getUsersBlogs</methodName>
			<params>
				<param><value><string>{$username}</string></value></param>
				<param><value><string>{$application_password}</string></value></param>
			</params>
		</methodCall>
		XML;

		$response = $client->request('POST', $url, [
			'headers' => $this->build_request_headers( 'RESTRICT' ),
			'body'    => $xml_payload,
			'auth'    => [ $username, $application_password ],
		]);

		$this->assertXmlStringEqualsXmlString(
			<<<XML
				<methodResponse>
						<params>
								<param>
										<value>
												<array>
														<data>
																<value>
																		<struct>
																				<member><name>isAdmin</name><value><boolean>1</boolean></value></member>
																				<member><name>isPrimary</name><value><boolean>1</boolean></value></member>
																				<member><name>url</name><value><string>http://vip-security-boost.vipdev.lndo.site/</string></value></member>
																				<member><name>blogid</name><value><string>1</string></value></member>
																				<member><name>blogName</name><value><string>VIP Security Boost</string></value></member>
																				<member><name>xmlrpc</name><value><string>http://vip-security-boost.vipdev.lndo.site/xmlrpc.php</string></value></member>
																		</struct>
																</value>
														</data>
												</array>
										</value>
								</param>
						</params>
				</methodResponse>
			XML,
			$response->getBody()->getContents()
		);
	}

	/**
	 * Generates an application password for the vipgo user.
	 *
	 * @return string The generated application password.
	 */
	protected function generate_application_password(): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		$cli_output     = shell_exec( 'lando wp user application-password create vipgo my_app --porcelain --path=/app/public' );
		$trimmed_output = trim( $cli_output );
		return $trimmed_output;
	}

	/**
	 * Encodes the configuration array into a base64 string for the header.
	 *
	 * @param array $configs The configuration array.
	 * @return string The base64 encoded configuration string.
	 */
	protected function encode_config_header( array $configs ): string {
		// Use wp_json_encode for consistency and potential WordPress filters/hooks.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return base64_encode( wp_json_encode( $configs ) );
	}

	/**
	 * Builds the request headers with the specified XML-RPC mode.
	 *
	 * @param string $mode The XML-RPC mode ('DISABLE' or 'RESTRICT').
	 * @return array The headers array.
	 */
	protected function build_request_headers( $mode ): array {
		return [
			'X-Integration-Test'         => 'true',
			'X-Integration-Test-Configs' => $this->encode_config_header(
				array(
					'enabled_modules' => [ 'restrict-xmlrpc' ],
					'module_configs'  => [
						'restrict-xmlrpc' => [ 'mode' => $mode ],
					],
				)
			),
		];
	}
}
