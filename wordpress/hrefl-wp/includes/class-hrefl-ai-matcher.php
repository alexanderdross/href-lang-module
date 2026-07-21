<?php
/**
 * Tier-C AI adjudication: choose the true equivalent among candidates.
 *
 * Provider-neutral (Microsoft Copilot or Anthropic, selectable), metadata-only
 * by default. Given a SOURCE page and a short candidate list (surfaced by Tier
 * A/B), the model picks one candidate or "none" - it never invents a URL. All
 * output is a proposal subject to human review. Mirrors the Drupal
 * AiMatcherBase + Copilot/Anthropic providers.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Ai_Matcher {

    /**
     * Whether an AI provider is configured (provider + endpoint + model + key).
     */
    public function is_configured(): bool {
        $provider = (string) Hrefl_Settings::get('ai_provider', '');
        return in_array($provider, ['anthropic', 'copilot'], true)
            && (string) Hrefl_Settings::get('ai_endpoint', '') !== ''
            && (string) Hrefl_Settings::get('ai_model', '') !== ''
            && $this->api_key() !== '';
    }

    /**
     * Adjudicate which candidate is the equivalent of the source page.
     *
     * @param array<string,mixed>        $source
     * @param array<int,array<string,mixed>> $candidates
     *
     * @return array{choice:?int,confidence:float,rationale:string}
     */
    public function adjudicate(array $source, array $candidates): array {
        if (!$candidates) {
            return self::no_decision('no candidates');
        }
        if (!$this->is_configured()) {
            return self::no_decision('provider not configured');
        }
        $text = $this->chat($this->system_instruction(), $this->user_message($source, $candidates));
        if ($text === null) {
            return self::no_decision('request failed');
        }
        return self::parse_answer($text, count($candidates));
    }

    /* ---- transport --------------------------------------------------- */

    /**
     * One chat call to the configured provider; response text or null.
     */
    private function chat(string $system, string $user): ?string {
        $provider = (string) Hrefl_Settings::get('ai_provider', '');
        $endpoint = (string) Hrefl_Settings::get('ai_endpoint', '');
        $model    = (string) Hrefl_Settings::get('ai_model', '');

        if ($provider === 'anthropic') {
            $args = [
                'headers' => [
                    'x-api-key'         => $this->api_key(),
                    'anthropic-version' => (string) Hrefl_Settings::get('ai_api_version', '2023-06-01'),
                    'content-type'      => 'application/json',
                ],
                'body' => wp_json_encode([
                    'model'       => $model,
                    'max_tokens'  => 300,
                    'temperature' => 0.0,
                    'system'      => $system,
                    'messages'    => [['role' => 'user', 'content' => $user]],
                ]),
                'timeout' => 30,
            ];
            $pick = static fn(array $p): string => (string) ($p['content'][0]['text'] ?? '');
        } else {
            // Copilot / Azure OpenAI chat-completions style.
            $args = [
                'headers' => [
                    'authorization' => 'Bearer ' . $this->api_key(),
                    'content-type'  => 'application/json',
                ],
                'body' => wp_json_encode([
                    'model'           => $model,
                    'temperature'     => 0.0,
                    'max_tokens'      => 300,
                    'response_format' => ['type' => 'json_object'],
                    'messages'        => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                ]),
                'timeout' => 30,
            ];
            $pick = static fn(array $p): string => (string) ($p['choices'][0]['message']['content'] ?? '');
        }

        $resp = Hrefl_Http::post_json($endpoint, $args);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) >= 300) {
            error_log('[hrefl] AI request failed: ' . (is_wp_error($resp) ? $resp->get_error_message() : (string) wp_remote_retrieve_response_code($resp)));
            return null;
        }
        $payload = json_decode((string) wp_remote_retrieve_body($resp), true);
        return is_array($payload) ? $pick($payload) : '';
    }

    /**
     * The API key: provider env constant first, then the stored option.
     */
    private function api_key(): string {
        $provider = (string) Hrefl_Settings::get('ai_provider', '');
        $const = $provider === 'anthropic' ? 'HREFL_ANTHROPIC_KEY' : 'HREFL_COPILOT_KEY';
        if (defined($const) && constant($const)) {
            return (string) constant($const);
        }
        return (string) Hrefl_Settings::get('ai_key', '');
    }

    /* ---- prompt + parsing (identical contract to Drupal) ------------- */

    private function system_instruction(): string {
        return implode(' ', [
            'You match localized versions of web pages across country markets.',
            'Given a SOURCE page and a numbered list of CANDIDATE pages, choose the single candidate',
            'that is the same content localized for another market, or none if there is no good match.',
            'Only choose from the supplied candidates. Never invent a URL.',
            'Respond with strict JSON only: {"choice": <index or null>, "confidence": <0..1>, "rationale": "<short>"}.',
        ]);
    }

    /**
     * @param array<string,mixed> $source
     * @param array<int,array<string,mixed>> $candidates
     */
    private function user_message(array $source, array $candidates): string {
        $lines = ['SOURCE:', $this->render_record($source), '', 'CANDIDATES:'];
        foreach (array_values($candidates) as $i => $candidate) {
            $lines[] = '[' . $i . '] ' . $this->render_record($candidate);
        }
        return implode("\n", $lines);
    }

    /**
     * Render one record to the allowed data scope (metadata by default).
     *
     * @param array<string,mixed> $record
     */
    private function render_record(array $record): string {
        $parts = [
            'market=' . ($record['market'] ?? ''),
            'lang=' . ($record['language'] ?? $record['lang'] ?? ''),
            'title=' . ($record['title'] ?? ''),
            'url=' . ($record['url'] ?? ''),
        ];
        if (Hrefl_Settings::get('ai_data_scope', 'metadata') === 'full' && !empty($record['body_excerpt'])) {
            $parts[] = 'body=' . mb_substr((string) $record['body_excerpt'], 0, 1500);
        }
        return implode(' ; ', $parts);
    }

    /**
     * Parse + validate the provider's JSON answer against the candidate count.
     *
     * @return array{choice:?int,confidence:float,rationale:string}
     */
    public static function parse_answer(string $raw, int $candidate_count): array {
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $raw = $m[0];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return self::no_decision('unparseable response');
        }
        $choice = $data['choice'] ?? null;
        if (!is_int($choice) || $choice < 0 || $choice >= $candidate_count) {
            $choice = null;
        }
        $confidence = max(0.0, min(1.0, (float) ($data['confidence'] ?? 0.0)));
        return [
            'choice'     => $choice,
            'confidence' => $confidence,
            'rationale'  => (string) ($data['rationale'] ?? ''),
        ];
    }

    /**
     * @return array{choice:?int,confidence:float,rationale:string}
     */
    public static function no_decision(string $reason): array {
        return ['choice' => null, 'confidence' => 0.0, 'rationale' => $reason];
    }
}
