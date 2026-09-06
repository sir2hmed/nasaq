<?php

namespace App\Services;

class WorkflowGraphValidator
{
    /**
     * @param  array<string, mixed>  $graph
     * @return array{valid: bool, errors: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}
     */
    public function validate(array $graph): array
    {
        $nodes = collect($graph['nodes'] ?? []);
        $edges = collect($graph['edges'] ?? []);
        $errors = [];
        $warnings = [];

        if ($nodes->isEmpty()) {
            $errors[] = $this->issue('no_start_node', 'The workflow needs at least one executable start node.');

            return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
        }

        $nodesById = $nodes->keyBy('id');
        $incoming = array_fill_keys($nodesById->keys()->all(), 0);
        $outgoing = array_fill_keys($nodesById->keys()->all(), []);

        foreach ($edges as $edge) {
            $source = $edge['source'] ?? null;
            $target = $edge['target'] ?? null;
            $edgeId = $edge['id'] ?? null;

            if ($source === $target) {
                $errors[] = $this->issue('self_loop', 'A node cannot connect to itself.', edgeId: $edgeId);

                continue;
            }

            if (! $nodesById->has($source) || ! $nodesById->has($target)) {
                $errors[] = $this->issue('dangling_edge', 'Every connection must reference two existing nodes.', edgeId: $edgeId);

                continue;
            }

            $outgoing[$source][] = $target;
            $incoming[$target]++;

            $sourceType = $nodesById->get($source)['type'];
            $targetType = $nodesById->get($target)['type'];
            if (! $this->isCompatible($sourceType, $targetType)) {
                $errors[] = $this->issue(
                    'incompatible_contract',
                    "The $sourceType output cannot connect to the $targetType input.",
                    edgeId: $edgeId,
                );
            }
        }

        $startNodes = collect($incoming)->filter(fn (int $count): bool => $count === 0)->keys();
        if ($startNodes->isEmpty()) {
            $errors[] = $this->issue('no_start_node', 'The workflow needs at least one executable start node.');
        }

        if ($nodes->count() > 1) {
            foreach ($nodesById as $nodeId => $node) {
                if ($incoming[$nodeId] === 0 && $outgoing[$nodeId] === []) {
                    $errors[] = $this->issue(
                        'dangling_node',
                        'Every node must participate in the execution path.',
                        nodeId: $nodeId,
                    );
                }
            }
        }

        if ($this->containsCycle($incoming, $outgoing)) {
            $errors[] = $this->issue('cycle', 'Workflow connections must form a directed acyclic graph.');
        }

        foreach ($nodes as $node) {
            foreach ($this->missingConfig($node['type'], $node['config'] ?? []) as $field) {
                $warnings[] = $this->issue(
                    'required_config',
                    "Configure the required field: $field.",
                    nodeId: $node['id'],
                    field: $field,
                );
            }
            foreach ($this->invalidConfig($node['type'], $node['config'] ?? []) as $invalid) {
                $warnings[] = $this->issue(
                    'invalid_config',
                    $invalid['message'],
                    nodeId: $node['id'],
                    field: $invalid['field'],
                );
            }
        }

        return [
            'valid' => $errors === [] && $warnings === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /** @param array<string, int> $incoming @param array<string, array<int, string>> $outgoing */
    private function containsCycle(array $incoming, array $outgoing): bool
    {
        $queue = array_keys(array_filter($incoming, fn (int $count): bool => $count === 0));
        $visited = 0;

        while ($queue !== []) {
            $nodeId = array_shift($queue);
            $visited++;

            foreach ($outgoing[$nodeId] as $target) {
                $incoming[$target]--;
                if ($incoming[$target] === 0) {
                    $queue[] = $target;
                }
            }
        }

        return $visited !== count($incoming);
    }

    private function isCompatible(string $sourceType, string $targetType): bool
    {
        $compatibleTargets = [
            'researcher' => ['writer'],
            'writer' => ['export', 'video', 'approval', 'publisher', 'email'],
            'export' => ['approval', 'publisher', 'email'],
            'video' => ['approval', 'publisher', 'email', 'export'],
            'approval' => ['publisher', 'email', 'export'],
            'publisher' => ['email'],
            'email' => [],
        ];

        return in_array($targetType, $compatibleTargets[$sourceType] ?? [], true);
    }

    /** @param array<string, mixed> $config @return array<int, string> */
    private function missingConfig(string $agentType, array $config): array
    {
        $required = match ($agentType) {
            'researcher' => ['topic', 'source_count', 'language'],
            'writer' => ['style', 'length', 'format', 'language'],
            'video' => ['scene_duration', 'max_scenes', 'narration'],
            'export' => ['formats'],
            'publisher' => ['destination'],
            'email' => ['recipients', 'subject'],
            default => [],
        };

        return array_values(array_filter($required, function (string $field) use ($config): bool {
            $value = $config[$field] ?? null;

            return $value === null || $value === '' || (is_array($value) && $value === []);
        }));
    }

    /**
     * Validate values that are present. Missing required values are reported separately so
     * the editor can distinguish an unfinished node from an invalid configured value.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, array{field: string, message: string}>
     */
    private function invalidConfig(string $agentType, array $config): array
    {
        $issues = [];
        $add = function (string $field, string $message) use (&$issues): void {
            $issues[] = ['field' => $field, 'message' => $message];
        };
        $present = fn (string $field): bool => array_key_exists($field, $config)
            && $config[$field] !== null
            && $config[$field] !== ''
            && $config[$field] !== [];

        if ($agentType === 'researcher') {
            if ($present('topic') && (! is_string($config['topic']) || trim($config['topic']) === '')) {
                $add('topic', 'Researcher topic must be non-empty text.');
            }
            if ($present('source_count') && (! is_int($config['source_count']) || $config['source_count'] < 1 || $config['source_count'] > 20)) {
                $add('source_count', 'Researcher source count must be an integer from 1 to 20.');
            }
            if ($present('language') && ! in_array($config['language'], ['en', 'ar'], true)) {
                $add('language', 'Researcher language must be English or Arabic.');
            }
            if ($present('search_depth') && ! in_array($config['search_depth'], ['basic', 'advanced'], true)) {
                $add('search_depth', 'Researcher search depth must be basic or advanced.');
            }
            if ($present('simulate_transient_failures') && (! is_int($config['simulate_transient_failures']) || $config['simulate_transient_failures'] < 0 || $config['simulate_transient_failures'] > 3)) {
                $add('simulate_transient_failures', 'Simulated transient failures must be an integer from 0 to 3.');
            }
        }

        if ($agentType === 'writer') {
            $this->checkChoice($config, 'style', ['professional', 'educational', 'conversational'], $add, 'Writer style is unsupported.');
            $this->checkChoice($config, 'length', ['short', 'medium', 'long'], $add, 'Writer length is unsupported.');
            $this->checkChoice($config, 'format', ['article', 'script', 'summary'], $add, 'Writer format is unsupported.');
            $this->checkChoice($config, 'language', ['same_as_input', 'en', 'ar'], $add, 'Writer language is unsupported.');
        }

        if ($agentType === 'video') {
            if ($present('scene_duration') && ((! is_int($config['scene_duration']) && ! is_float($config['scene_duration'])) || $config['scene_duration'] < 1 || $config['scene_duration'] > 10)) {
                $add('scene_duration', 'Video scene duration must be between 1 and 10 seconds.');
            }
            if ($present('max_scenes') && (! is_int($config['max_scenes']) || $config['max_scenes'] < 1 || $config['max_scenes'] > 8)) {
                $add('max_scenes', 'Video scene count must be an integer from 1 to 8.');
            }
            $this->checkChoice($config, 'narration', ['silent', 'tts'], $add, 'Video narration must be silent or TTS.');
            if ($present('voice') && (! is_string($config['voice']) || trim($config['voice']) === '' || mb_strlen($config['voice']) > 80)) {
                $add('voice', 'Video narration voice must be non-empty text up to 80 characters.');
            }
        }

        if ($agentType === 'export' && $present('formats')) {
            $formats = $config['formats'];
            $validFormats = is_array($formats);
            if ($validFormats) {
                foreach ($formats as $format) {
                    if (! is_string($format) || ! in_array($format, ['markdown', 'pdf', 'docx'], true)) {
                        $validFormats = false;
                        break;
                    }
                }
            }
            if (! $validFormats || count($formats) !== count(array_unique($formats))) {
                $add('formats', 'Export formats must be unique Markdown, PDF, or DOCX values.');
            }
        }

        if ($agentType === 'publisher') {
            $this->checkChoice($config, 'destination', ['google_drive', 'youtube'], $add, 'Publisher destination must be Google Drive or YouTube.');
            if ($present('privacy_status') && ! in_array($config['privacy_status'], ['private', 'unlisted', 'public'], true)) {
                $add('privacy_status', 'YouTube privacy must be private, unlisted, or public.');
            }
            if ($present('title') && (! is_string($config['title']) || mb_strlen($config['title']) > 160)) {
                $add('title', 'Publication title must be text up to 160 characters.');
            }
        }

        if ($agentType === 'email') {
            if ($present('recipients')) {
                $recipients = $config['recipients'];
                $validRecipients = is_array($recipients) && count($recipients) >= 1 && count($recipients) <= 50;
                if ($validRecipients) {
                    foreach ($recipients as $recipient) {
                        if (! is_string($recipient) || mb_strlen($recipient) > 254 || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                            $validRecipients = false;
                            break;
                        }
                    }
                }
                if (! $validRecipients) {
                    $add('recipients', 'Email requires 1 to 50 valid recipient addresses.');
                }
            }
            if ($present('subject') && (! is_string($config['subject']) || trim($config['subject']) === '' || mb_strlen($config['subject']) > 998 || str_contains($config['subject'], "\n") || str_contains($config['subject'], "\r"))) {
                $add('subject', 'Email subject must be non-empty text without line breaks.');
            }
            if ($present('body_template') && (! is_string($config['body_template']) || mb_strlen($config['body_template']) > 20000)) {
                $add('body_template', 'Email body template must be text up to 20,000 characters.');
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $allowed
     * @param  callable(string, string): void  $add
     */
    private function checkChoice(array $config, string $field, array $allowed, callable $add, string $message): void
    {
        if (array_key_exists($field, $config)
            && $config[$field] !== null
            && $config[$field] !== ''
            && ! in_array($config[$field], $allowed, true)) {
            $add($field, $message);
        }
    }

    /** @return array<string, mixed> */
    private function issue(
        string $code,
        string $message,
        ?string $nodeId = null,
        ?string $edgeId = null,
        ?string $field = null,
    ): array {
        return array_filter([
            'code' => $code,
            'message' => $message,
            'node_id' => $nodeId,
            'edge_id' => $edgeId,
            'field' => $field,
        ], fn (mixed $value): bool => $value !== null);
    }
}
