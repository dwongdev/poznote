<?php
/**
 * Shared speech-to-text (transcription) configuration.
 *
 * Poznote does not embed a speech model. It talks to a server exposing the
 * OpenAI audio API, POST /v1/audio/transcriptions, which every Whisper
 * distribution worth self-hosting speaks: Speaches (ex faster-whisper-server),
 * whisper.cpp's server, LocalAI, and OpenAI itself for those who want it.
 * Point the setting at a container on your own machine and the audio never
 * leaves it.
 *
 * The configuration follows the AI assistant to the letter (see ai_config.php):
 *   - an instance one in master.db (global_settings), managed by an
 *     administrator in stt_settings.php and granted per profile through the
 *     allowed-users list;
 *   - a personal one in the user's own database, set in stt_settings_user.php
 *     when the administrator allows personal servers.
 *
 * A personal configuration always wins over the instance one.
 *
 * API keys are encrypted with the helpers of ai_config.php rather than a
 * second implementation: same instance secret, same format.
 */

require_once __DIR__ . '/users/db_master.php';
require_once __DIR__ . '/ai_config.php';

/** Providers offered by both settings pages. */
function poznoteSttProviders(): array {
    return ['speaches', 'whispercpp', 'localai', 'openai', 'custom'];
}

/** Providers whose URL is fixed (the URL field is hidden in the UI). */
function poznoteSttFixedUrls(): array {
    return ['openai' => 'https://api.openai.com'];
}

/** Bounds of the maximum recording length setting, in minutes. */
const POZNOTE_STT_MAX_RECORDING_MINUTES_DEFAULT = 10;
const POZNOTE_STT_MAX_RECORDING_MINUTES_LIMIT = 60;

/** Clamp a posted or stored value to the allowed range, default when unusable. */
function poznoteSttNormalizeMaxRecordingMinutes($value): int {
    $minutes = filter_var($value, FILTER_VALIDATE_INT);
    if ($minutes === false) {
        return POZNOTE_STT_MAX_RECORDING_MINUTES_DEFAULT;
    }
    return max(1, min(POZNOTE_STT_MAX_RECORDING_MINUTES_LIMIT, $minutes));
}

/**
 * Longest dictation, in minutes, set by the administrator on the Transcription
 * page. The browser stops and transcribes on its own when it gets there, so a
 * tab left recording cannot hand the server an hour of audio.
 *
 * It is enforced in the browser, which is the only place that knows the
 * duration without decoding the file; poznoteSttMaxUploadBytes() is the
 * server-side backstop for anyone posting to the endpoint directly.
 */
function poznoteSttMaxRecordingMinutes(): int {
    return poznoteSttNormalizeMaxRecordingMinutes(
        getGlobalSetting('stt_max_recording_minutes', (string)POZNOTE_STT_MAX_RECORDING_MINUTES_DEFAULT)
    );
}

function poznoteSttMaxRecordingSeconds(): int {
    return poznoteSttMaxRecordingMinutes() * 60;
}

/**
 * How long a transcription may run, in seconds. Kept under the 600 seconds
 * nginx gives PHP (fastcgi_read_timeout in docker/nginx/default.conf) so the
 * endpoint times out first and answers with a message rather than a 504 page.
 * A reverse proxy in front of Poznote can still cut the request earlier.
 */
const POZNOTE_STT_UPSTREAM_TIMEOUT_SECONDS = 570;

/** Largest audio payload accepted by the transcription endpoint, in bytes. */
function poznoteSttMaxUploadBytes(): int {
    return 100 * 1024 * 1024;
}

/**
 * post_max_size in bytes, or 0 when it is unlimited or unreadable.
 *
 * PHP drops a body over this limit before any of our code runs, leaving an
 * empty $_POST and $_FILES and no upload error, so the endpoint compares the
 * declared body length against this to tell "too large" from "no file".
 */
function poznoteSttPostMaxBytes(): int {
    $raw = trim((string)ini_get('post_max_size'));
    if ($raw === '' || $raw === '0' || $raw === '-1') {
        return 0;
    }
    $unit = strtolower(substr($raw, -1));
    $value = (int)$raw;
    switch ($unit) {
        case 'g': return $value * 1024 * 1024 * 1024;
        case 'm': return $value * 1024 * 1024;
        case 'k': return $value * 1024;
        default: return $value;
    }
}

/**
 * Audio types the transcription endpoint accepts. Whisper servers read these
 * through ffmpeg; the list is here to refuse everything else before a file
 * reaches the network, not to promise a given server handles all of them.
 */
function poznoteSttAllowedMimeTypes(): array {
    return [
        'audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp3', 'audio/mp4',
        'audio/m4a', 'audio/x-m4a', 'audio/wav', 'audio/x-wav', 'audio/wave',
        'audio/flac', 'audio/x-flac', 'audio/aac', 'audio/opus', 'video/webm',
    ];
}

/**
 * Extension to send the server with the uploaded part. Whisper servers dispatch
 * on the filename, so a WebM blob named "audio" is refused by some of them.
 */
function poznoteSttExtensionForMimeType(string $mimeType): string {
    static $map = [
        'audio/webm' => 'webm', 'video/webm' => 'webm',
        'audio/ogg' => 'ogg', 'audio/opus' => 'ogg',
        'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3',
        'audio/mp4' => 'm4a', 'audio/m4a' => 'm4a', 'audio/x-m4a' => 'm4a',
        'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav',
        'audio/flac' => 'flac', 'audio/x-flac' => 'flac',
        'audio/aac' => 'aac',
    ];
    // A type can carry parameters: "audio/webm;codecs=opus"
    $bare = strtolower(trim(explode(';', $mimeType)[0]));
    return $map[$bare] ?? 'wav';
}

/**
 * Audio extensions that decide transcription eligibility on their own, and the
 * type each one is sent to the server with.
 */
function poznoteSttAudioExtensionTypes(): array {
    return [
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg',
        'opus' => 'audio/ogg', 'm4a' => 'audio/mp4', 'flac' => 'audio/flac', 'aac' => 'audio/aac',
    ];
}

/**
 * Whether an attachment can be sent for transcription.
 *
 * Not poznoteAttachmentPreviewKind(): that one checks video/ before audio/, and
 * Windows uploads a Voice Recorder .m4a as video/mp4, so a plain voice memo came
 * out as a video and was refused. For transcription an audio extension wins over
 * the recorded type. A .webm or .mp4 stays out unless its type says audio: the
 * extension alone cannot tell a voice note from a film.
 */
function poznoteSttAttachmentIsTranscribable(array $attachment): bool {
    $extension = strtolower((string)pathinfo((string)($attachment['original_filename'] ?? $attachment['filename'] ?? ''), PATHINFO_EXTENSION));
    if (isset(poznoteSttAudioExtensionTypes()[$extension])) {
        return true;
    }
    $mimeType = strtolower(trim((string)($attachment['file_type'] ?? $attachment['mime_type'] ?? $attachment['type'] ?? '')));
    return strpos($mimeType, 'audio/') === 0;
}

/**
 * Type to send the server for a stored attachment: the extension's audio type
 * when it has one (the recorded type can be the video/mp4 above), otherwise the
 * recorded type, otherwise a generic one.
 */
function poznoteSttAttachmentMimeType(array $attachment): string {
    $extension = strtolower((string)pathinfo((string)($attachment['original_filename'] ?? $attachment['filename'] ?? ''), PATHINFO_EXTENSION));
    $byExtension = poznoteSttAudioExtensionTypes()[$extension] ?? '';
    if ($byExtension !== '') {
        return $byExtension;
    }
    $mimeType = strtolower(trim((string)($attachment['file_type'] ?? $attachment['mime_type'] ?? $attachment['type'] ?? '')));
    return $mimeType !== '' ? $mimeType : 'application/octet-stream';
}

/**
 * True when the administrator lets users configure their own transcription
 * server. Applies to every user: the allowed-users list of stt_settings.php
 * only governs access to the instance configuration.
 */
function poznoteSttUserKeysAllowed(): bool {
    return (string)getGlobalSetting('stt_user_keys_enabled', '0') === '1';
}

/** Instance-wide configuration (master.db), managed by an administrator. */
function poznoteSttInstanceConfig(): array {
    return [
        'enabled' => (string)getGlobalSetting('stt_enabled', '0') === '1',
        'provider' => (string)getGlobalSetting('stt_provider', ''),
        'url' => trim((string)getGlobalSetting('stt_url', '')),
        'model' => trim((string)getGlobalSetting('stt_model', '')),
        'api_key' => trim((string)getGlobalSetting('stt_api_key', '')),
        'language' => poznoteSttNormalizeLanguage(getGlobalSetting('stt_language', '')),
    ];
}

/** Keys used in the user database settings table. */
function poznoteSttUserSettingKeys(): array {
    return [
        'enabled' => 'stt_user_enabled',
        'provider' => 'stt_user_provider',
        'url' => 'stt_user_url',
        'model' => 'stt_user_model',
        'api_key' => 'stt_user_api_key',
        'language' => 'stt_user_language',
    ];
}

/**
 * Spoken language hint sent as the `language` parameter. Empty means the
 * server detects it, which is what Whisper does well and what most people
 * want; a fixed code is for those who dictate in one language and are tired
 * of the detector picking another on a short sentence.
 *
 * Kept to a plain ISO 639-1 code so nothing exotic reaches the server.
 */
function poznoteSttNormalizeLanguage($value): string {
    $value = strtolower(trim((string)$value));
    return preg_match('/^[a-z]{2}$/', $value) === 1 ? $value : '';
}

/**
 * Personal configuration read from the user's own database.
 * The API key comes back decrypted.
 */
function poznoteSttUserConfig(?PDO $con): array {
    $config = ['enabled' => false, 'provider' => '', 'url' => '', 'model' => '', 'api_key' => '', 'language' => ''];
    if (!$con) {
        return $config;
    }
    try {
        $keys = poznoteSttUserSettingKeys();
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $con->prepare("SELECT key, value FROM settings WHERE key IN ($placeholders)");
        $stmt->execute(array_values($keys));
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[$row['key']] = (string)($row['value'] ?? '');
        }
        $config['enabled'] = ($rows[$keys['enabled']] ?? '0') === '1';
        $config['provider'] = trim($rows[$keys['provider']] ?? '');
        $config['url'] = trim($rows[$keys['url']] ?? '');
        $config['model'] = trim($rows[$keys['model']] ?? '');
        $config['api_key'] = poznoteAiDecryptSecret($rows[$keys['api_key']] ?? '');
        $config['language'] = poznoteSttNormalizeLanguage($rows[$keys['language']] ?? '');
    } catch (Exception $e) {
        // A database without the settings table yet: no personal configuration
        error_log('stt_config: poznoteSttUserConfig() failed: ' . $e->getMessage());
    }
    return $config;
}

/**
 * Save the personal configuration. Only the keys present in $config are
 * written, so an omitted api_key keeps the stored one.
 */
function poznoteSaveSttUserConfig(?PDO $con, array $config): bool {
    if (!$con) {
        return false;
    }
    try {
        $keys = poznoteSttUserSettingKeys();
        $stmt = $con->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
        foreach ($keys as $inputKey => $dbKey) {
            if (!array_key_exists($inputKey, $config)) {
                continue;
            }
            $value = trim((string)$config[$inputKey]);
            if ($inputKey === 'api_key') {
                $value = poznoteAiEncryptSecret($value);
            } elseif ($inputKey === 'language') {
                $value = poznoteSttNormalizeLanguage($value);
            }
            $stmt->execute([$dbKey, $value]);
        }
        return true;
    } catch (Exception $e) {
        error_log('Failed to save personal transcription configuration: ' . $e->getMessage());
        return false;
    }
}

/**
 * A configuration can transcribe once it has a URL and a model.
 *
 * whisper.cpp serves the single model it was started with and ignores the
 * parameter, but it still has to be sent, so the field is required for every
 * provider rather than special-cased.
 */
function poznoteSttConfigUsable(array $config): bool {
    return !empty($config['enabled']) && trim((string)$config['url']) !== '' && trim((string)$config['model']) !== '';
}

/**
 * The configuration transcription must use for this user.
 * Returns ['available' => bool, 'source' => 'user'|'instance'|'', 'url', 'model', 'api_key', 'language'].
 */
function poznoteResolveSttConfig(?PDO $con, ?int $userId): array {
    $none = ['available' => false, 'source' => '', 'url' => '', 'model' => '', 'api_key' => '', 'language' => ''];

    if (poznoteSttUserKeysAllowed()) {
        $userConfig = poznoteSttUserConfig($con);
        if (poznoteSttConfigUsable($userConfig)) {
            return [
                'available' => true,
                'source' => 'user',
                'url' => $userConfig['url'],
                'model' => $userConfig['model'],
                'api_key' => $userConfig['api_key'],
                'language' => $userConfig['language'],
            ];
        }
    }

    $instance = poznoteSttInstanceConfig();
    // Access to the instance configuration is opt-in, granted per user by an
    // administrator in stt_settings.php
    if (poznoteSttConfigUsable($instance) && isSttAllowedForUser($userId)) {
        return [
            'available' => true,
            'source' => 'instance',
            'url' => $instance['url'],
            'model' => $instance['model'],
            'api_key' => $instance['api_key'],
            'language' => $instance['language'],
        ];
    }

    return $none;
}

/**
 * Infer the provider from a URL, for configurations saved by hand or before a
 * provider was recorded.
 */
function poznoteSttGuessProvider(string $provider, string $url): string {
    if (in_array($provider, poznoteSttProviders(), true)) {
        return $provider;
    }
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
    $port = (int)(parse_url($url, PHP_URL_PORT) ?? 0);
    if ($host === 'api.openai.com') {
        return 'openai';
    }
    if ($port === 8000) {
        return 'speaches';
    }
    return ($url === '') ? 'speaches' : 'custom';
}

/**
 * Build the transcriptions URL from the configured base URL.
 * Accepts "http://host:8000", "http://host:8000/v1" or a full
 * ".../audio/transcriptions" URL.
 */
function poznoteSttTranscriptionsUrl(string $baseUrl): string {
    $url = rtrim(trim($baseUrl), '/');
    if ($url === '') return '';
    if (substr($url, -21) === '/audio/transcriptions') return $url;
    if (substr($url, -3) !== '/v1') $url .= '/v1';
    return $url . '/audio/transcriptions';
}

/** Models URL of the same server, used by the connection test. */
function poznoteSttModelsUrl(string $baseUrl): string {
    $url = rtrim(trim($baseUrl), '/');
    if ($url === '') return '';
    if (substr($url, -21) === '/audio/transcriptions') {
        $url = substr($url, 0, -21);
    }
    if (substr($url, -3) !== '/v1') $url .= '/v1';
    return $url . '/models';
}
