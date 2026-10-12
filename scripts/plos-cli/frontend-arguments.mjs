/** Shared launch contract for ordinary sessions and native terminal qualification. */
export function resumeArguments(selection = null) {
    if (selection === null) return [];
    if (selection === 'last') return ['resume', '--last'];
    if (typeof selection !== 'string' || !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(selection)) {
        throw new Error('Resume requires a saved session UUID or last.');
    }
    return ['resume', selection];
}

export function frontendArguments(endpoint, workspace, resume = null) {
    return [
        '--remote', endpoint, '--remote-auth-token-env', 'PLOS_CLI_TRANSPORT_TOKEN',
        '--no-alt-screen', '-C', workspace,
        '-c', 'check_for_update_on_startup=false', '-c', 'analytics.enabled=false', '-c', 'feedback.enabled=false',
        '-c', 'model_provider="plos"', '-c', 'model="plos-unconfigured"',
        '-c', `model_providers.plos={name="PLOS",base_url="${endpoint.replace('ws:', 'http:')}",wire_api="responses",requires_openai_auth=false}`,
        ...resumeArguments(resume),
    ];
}
