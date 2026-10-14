<?php
declare(strict_types=1);

function passkey_config(): array {
    $origin = rtrim((string) env('WEBAUTHN_ORIGIN', (string) env('APP_URL', '')), '/');
    $parts = parse_url($origin);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($host === '' || ($scheme !== 'https' && $host !== 'localhost' && $host !== '127.0.0.1')) {
        throw new RuntimeException('Passkeys require an HTTPS WEBAUTHN_ORIGIN (or localhost during development).');
    }
    $rpId = strtolower((string) env('WEBAUTHN_RP_ID', $host));
    if ($rpId === '' || ($host !== $rpId && !str_ends_with($host, '.'.$rpId))) {
        throw new RuntimeException('WEBAUTHN_RP_ID must match the application host or one of its parent domains.');
    }
    return ['origin' => $origin, 'rp_id' => $rpId];
}

function passkey_serializer(): \Symfony\Component\Serializer\SerializerInterface {
    static $serializer = null;
    if ($serializer !== null) return $serializer;
    $attestation = new \Webauthn\AttestationStatement\AttestationStatementSupportManager([
        new \Webauthn\AttestationStatement\NoneAttestationStatementSupport(),
    ]);
    return $serializer = (new \Webauthn\Denormalizer\WebauthnSerializerFactory($attestation))->create();
}

function passkey_ceremony_factory(): \Webauthn\CeremonyStep\CeremonyStepManagerFactory {
    $factory = new \Webauthn\CeremonyStep\CeremonyStepManagerFactory();
    $factory->setAllowedOrigins([passkey_config()['origin']]);
    return $factory;
}

function passkey_user_handle(int $userId): string { return 'command-cabinet-user-'.$userId; }
function passkey_b64url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }

function passkey_json(array $payload, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json'); echo json_encode($payload, JSON_THROW_ON_ERROR); exit;
}

function passkey_read_credential(): \Webauthn\PublicKeyCredential {
    $payload = json_decode((string) ($_POST['credential'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) throw new RuntimeException('Invalid passkey response.');
    return passkey_serializer()->deserialize(json_encode($payload, JSON_THROW_ON_ERROR), \Webauthn\PublicKeyCredential::class, 'json');
}

function passkey_active_state(string $key): array {
    $state = $_SESSION[$key] ?? null;
    unset($_SESSION[$key]);
    if (!is_array($state) || ($state['expires_at'] ?? 0) < time()) throw new RuntimeException('This passkey request expired. Please try again.');
    return $state;
}

function passkey_options_json(object $options): string {
    return passkey_serializer()->serialize($options, 'json', [\Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer::SKIP_NULL_VALUES => true]);
}

function passkey_record_from_row(array $row): \Webauthn\CredentialRecord {
    return passkey_serializer()->deserialize((string) $row['credential_data'], \Webauthn\CredentialRecord::class, 'json');
}

function passkey_begin_registration(array $user): never {
    $config = passkey_config();
    $existing = db()->prepare('SELECT credential_data FROM webauthn_credentials WHERE user_id=?');
    $existing->execute([$user['id']]);
    $excluded = [];
    foreach ($existing as $row) $excluded[] = passkey_record_from_row($row)->getPublicKeyCredentialDescriptor();
    $options = \Webauthn\PublicKeyCredentialCreationOptions::create(
        \Webauthn\PublicKeyCredentialRpEntity::create('', $config['rp_id']),
        \Webauthn\PublicKeyCredentialUserEntity::create($user['email'], passkey_user_handle((int) $user['id']), $user['name']),
        random_bytes(32),
        [],
        \Webauthn\AuthenticatorSelectionCriteria::create(null, 'required', 'preferred'),
        \Webauthn\PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
        $excluded,
        300000,
    );
    $json = passkey_options_json($options);
    $_SESSION['passkey_registration'] = ['user_id' => (int) $user['id'], 'options' => $json, 'expires_at' => time() + 300];
    passkey_json(['options' => json_decode($json, true, 512, JSON_THROW_ON_ERROR)]);
}

function passkey_finish_registration(array $user): never {
    $state = passkey_active_state('passkey_registration');
    if ((int) $state['user_id'] !== (int) $user['id']) throw new RuntimeException('Passkey request does not match this account.');
    $credential = passkey_read_credential();
    if (!$credential->response instanceof \Webauthn\AuthenticatorAttestationResponse) throw new RuntimeException('Invalid passkey registration response.');
    $options = passkey_serializer()->deserialize((string) $state['options'], \Webauthn\PublicKeyCredentialCreationOptions::class, 'json');
    $validator = \Webauthn\AuthenticatorAttestationResponseValidator::create(passkey_ceremony_factory()->creationCeremony());
    $record = $validator->check($credential->response, $options, passkey_config()['rp_id']);
    $label = trim((string) ($_POST['label'] ?? ''));
    if ($label === '') $label = 'Passkey';
    $label = mb_substr($label, 0, 120);
    $insert = db()->prepare('INSERT INTO webauthn_credentials(user_id,credential_id,credential_data,label,aaguid,backup_eligible,backup_status,uv_initialized) VALUES(?,?,?,?,?,?,?,?)');
    $insert->execute([(int) $user['id'], passkey_b64url($record->publicKeyCredentialId), passkey_serializer()->serialize($record, 'json'), $label, (string) $record->aaguid, $record->backupEligible, $record->backupStatus, $record->uvInitialized]);
    passkey_json(['ok' => true]);
}

function passkey_begin_login(string $email): never {
    $userQuery = db()->prepare('SELECT id,name,email FROM users WHERE email=?');
    $userQuery->execute([strtolower(trim($email))]); $user = $userQuery->fetch();
    if (!$user) passkey_json(['error' => 'This sign-in method is not available for those details.'], 422);
    $credentials = db()->prepare('SELECT credential_data FROM webauthn_credentials WHERE user_id=?');
    $credentials->execute([$user['id']]);
    $allowed = [];
    foreach ($credentials as $row) $allowed[] = passkey_record_from_row($row)->getPublicKeyCredentialDescriptor();
    if (!$allowed) passkey_json(['error' => 'This sign-in method is not available for those details.'], 422);
    $options = \Webauthn\PublicKeyCredentialRequestOptions::create(random_bytes(32), passkey_config()['rp_id'], $allowed, 'required', 300000);
    $json = passkey_options_json($options);
    $_SESSION['passkey_login'] = ['user_id' => (int) $user['id'], 'options' => $json, 'expires_at' => time() + 300];
    passkey_json(['options' => json_decode($json, true, 512, JSON_THROW_ON_ERROR)]);
}

function passkey_finish_login(): never {
    $state = passkey_active_state('passkey_login');
    $credential = passkey_read_credential();
    if (!$credential->response instanceof \Webauthn\AuthenticatorAssertionResponse) throw new RuntimeException('Invalid passkey sign-in response.');
    $credentialId = passkey_b64url($credential->rawId);
    $lookup = db()->prepare('SELECT * FROM webauthn_credentials WHERE user_id=? AND credential_id=?');
    $lookup->execute([(int) $state['user_id'], $credentialId]); $stored = $lookup->fetch();
    if (!$stored) throw new RuntimeException('This passkey is not registered to the account.');
    $options = passkey_serializer()->deserialize((string) $state['options'], \Webauthn\PublicKeyCredentialRequestOptions::class, 'json');
    $validator = \Webauthn\AuthenticatorAssertionResponseValidator::create(passkey_ceremony_factory()->requestCeremony());
    $record = $validator->check(passkey_record_from_row($stored), $credential->response, $options, passkey_config()['rp_id'], $credential->response->userHandle);
    db()->prepare('UPDATE webauthn_credentials SET credential_data=?,last_used_at=NOW(),backup_eligible=?,backup_status=?,uv_initialized=? WHERE id=?')->execute([passkey_serializer()->serialize($record, 'json'), $record->backupEligible, $record->backupStatus, $record->uvInitialized, $stored['id']]);
    session_regenerate_id(true); $_SESSION['user_id'] = (int) $state['user_id'];
    passkey_json(['ok' => true, 'redirect' => '/']);
}
