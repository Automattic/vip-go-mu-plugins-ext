export interface SecurityBoostConfig {
	enabled_modules: string[];
	available_modules: string[];
	module_configs: {
		'inactive-users'?: {
			mode: 'REPORT' | 'BLOCK';
			considered_inactive_after_days: number;
			roles: string[];
			capabilities: string[];
		};
		'forced-mfa-users'?: {
			roles: string[];
			capabilities: string[];
		};
		'xml-rpc'?: {
			mode: 'RESTRICT' | 'DISABLE' | 'ALLOW';
		};
		'session-control'?: {
			expiration_days: number | string;
		};
		'highlight-mfa-users'?: {
			roles: string[];
			capabilities: string[];
		};
		'notify-privileged-activity'?: {
			email_users: string[];
		};
	};
}
export const DEFAULT_CONFIG: SecurityBoostConfig = {
	enabled_modules: [
		'notify-privileged-activity',
		'highlight-mfa-users',
		'forced-mfa-users',
		'inactive-users',
		'session-control',
		'xml-rpc',
	],
	available_modules: [
		'inactive-users',
		'highlight-mfa-users',
		'forced-mfa-users',
		'xml-rpc',
		'session-control',
		'notify-privileged-activity',
	],
	module_configs: {
		'inactive-users': {
			mode: 'BLOCK',
			considered_inactive_after_days: 14,
			roles: [],
			capabilities: [ 'manage_options', 'edit_others_posts', 'publish_posts', 'edit_posts' ],
		},
		'forced-mfa-users': {
			roles: [],
			capabilities: [
				'manage_options',
				'edit_others_posts',
				'publish_posts',
			],
		},
		'xml-rpc': {
			mode: 'DISABLE',
		},
		'session-control': {
			expiration_days: 7,
		},
		'highlight-mfa-users': {
			roles: [],
			capabilities: [ 'manage_options', 'edit_others_posts' ],
		},
		'notify-privileged-activity': {
			email_users: [],
		},
	},
};
export function getSecurityBoostConfig(
	overrides?: Partial<SecurityBoostConfig>,
): SecurityBoostConfig {
	return {
		...DEFAULT_CONFIG,
		...overrides,
		module_configs: {
			...DEFAULT_CONFIG.module_configs,
			...overrides?.module_configs,
		},
		enabled_modules: overrides?.enabled_modules ?? DEFAULT_CONFIG.enabled_modules,
		available_modules:
			overrides?.available_modules ?? DEFAULT_CONFIG.available_modules,
	};
}

export function getSecurityBoostConfigHeaders(
	partialConfig?: Partial<SecurityBoostConfig>,
): { [key: string]: string } {
	return {
		'X-Integration-Test': 'true',
		'X-Integration-Test-Configs': Buffer.from(
			JSON.stringify( getSecurityBoostConfig( partialConfig ) ),
			'utf8',
		).toString( 'base64' ),
	};
}
