function shellQuote( value: string ): string {
    return `'${ value.replace( /'/g, "'\\''" ) }'`;
}

export function wpEnvRun( container: string ): string {
    const configPath = process.env.WP_ENV_CONFIG_PATH?.trim();
    const configArgs = configPath ? ` --config ${ shellQuote( configPath ) }` : '';

    return `npx wp-env${ configArgs } run ${ shellQuote( container ) }`;
}
