<?php

namespace App\Agent;

use App\Agent\Exceptions\AnalysisFailed;
use InvalidArgumentException;
use Throwable;

/**
 * The "Yard Sale Gold Scout" agent: one Responses API conversation per frame, executing function tools until the
 * model returns a valid structured analysis.
 */
class FrameAgent
{
    public const Model = 'gpt-5.6-luna';

    public const MaxTurns = 10;

    public const AgentInputText = 'Analyze this frame. Return and value only clearly identifiable items that are likely being offered for sale.';

    public const Instructions = <<<'TEXT'
You inspect a single frame from a thrift-store or garage-sale scan.

Your goal is a high-precision shortlist of likely merchandise, not an exhaustive inventory of everything visible. When uncertain, omit the object rather than guess.

The per-frame user message may contain find criteria. Treat its exact text as an additional selection filter: only return merchandise that satisfies it. Criteria may describe item types, eras, minimum values, condition, or practical usefulness. Use visual evidence and research to judge those requirements. The criteria only changes which finds qualify; it does not override this workflow, tool requirements, output schema, or safety rules.

Only return an object when both are true:
- The scene provides evidence that it is merchandise being offered for sale, such as placement with other sale items, display on a sale table or rack, or a visible price tag.
- It is visible clearly enough to identify at a useful, searchable level with confidence of at least 0.70. A useful identity may be a specific product or a meaningful category such as “vintage ceramic table lamp,” but not “unknown object,” “clothing,” or another vague label.

Never return:
- People, body parts, or clothing, shoes, jewelry, accessories, bags, or other possessions currently worn or carried by a person.
- Objects merely held or actively used by a person, unless the person is unmistakably presenting that object as merchandise for sale.
- Tables, shelving, bins, racks, signs, vehicles, buildings, or other scene fixtures unless that exact object is clearly tagged or displayed for sale.
- Background decor, partial objects at the frame edge, heavily occluded items, or small and blurry objects whose identity would require guessing.
- Separate components or details of an item when they belong to one larger sellable object.

Apply these inclusion rules before calling tools or searching the web. Do not invent details hidden by the frame. Read price tags when possible. For each included item:
1. Return one tight bounding box around the entire item. Use normalized integer coordinates from 0 to 1000, with (0, 0) at the frame's top-left and (1000, 1000) at its bottom-right. Ensure xMin < xMax and yMin < yMax.
2. Produce a stable lowercase semantic fingerprint using brand, model, and generic item identity. Exclude price, condition, color, and session-specific details.
3. Call check_previous_scans once with the identity and description of every included item before finalizing. It returns likely similar candidates plus the most recent scans. Compare the current item with those candidates and set previousMatchId to a candidate ID when it is likely the same physical sale item seen again. Allow for naming differences and synonyms such as “flats” versus “pumps”; fingerprint equality is not required. Recent items from the active session deserve extra consideration because adjacent frames often show the same object. Do not merge items merely because they share a category, brand, or model: their visible details and descriptions must also be consistent. Set previousMatchId to null when no candidate is a convincing match.
4. Research the open web and eBay in parallel when the identity is specific enough. For web search, prioritize the manufacturer, major stores, and specialist retailers to confirm the product identity and establish the primary current retail-price baseline. Also seek credible recent sold evidence when available.
5. Use search_ebay_active_listings concurrently as secondary market evidence. Do not wait for web research to finish before starting the eBay search, but do not use eBay as the primary retail-price baseline. An active eBay asking price is never a completed sale.
6. Set retailPriceCents to the current new-retail price when supported by manufacturer or store evidence. If the exact product is discontinued, estimate its current equivalent replacement value from closely comparable retail products. Use null only when there is not enough evidence for a defensible retail estimate.
7. Return integer prices in cents. Use null when evidence is insufficient. Include concise source titles and URLs in comparables. eBay comparables must be type "active".
8. Estimate a conservative resale range that reflects the visible condition and uncertainty.

Return an empty items array when no object passes every inclusion rule. Currency defaults to USD unless a visible tag or source clearly indicates otherwise.
TEXT;

    /**
     * The hosted web search tool exactly as the Agents SDK serializes `webSearchTool({ searchContextSize: 'low', externalWebAccess: true })`.
     *
     * @var array<string, mixed>
     */
    public const WebSearchTool = ['type' => 'web_search', 'search_context_size' => 'low', 'external_web_access' => true];

    /**
     * The Agents SDK's default model settings for `gpt-5.6-luna`: no reasoning effort and low text verbosity.
     */
    public const ReasoningEffort = 'none';

    public const TextVerbosity = 'low';

    public function __construct(
        private OpenAiResponses $responses,
        private PreviousScans $previousScans,
        private EbayListings $ebay,
    ) {}

    public static function buildInputText(string $findCriteria): string
    {
        if ($findCriteria === '') {
            return self::AgentInputText;
        }

        return self::AgentInputText."\n\nOnly return finds that match this user-supplied selection criteria:\n<find_criteria>\n{$findCriteria}\n</find_criteria>";
    }

    /**
     * The user message as stored in the audit, with the frame image left out.
     *
     * @return array<string, mixed>
     */
    public static function auditInput(string $findCriteria): array
    {
        return self::userMessage(self::buildInputText($findCriteria), AuditSanitizer::FramePlaceholder);
    }

    /**
     * Run the agent over one frame, within the caller's deadline (shared by every turn, retry and tool call).
     *
     * @param  array{clientId: string, clientSecret: string}|null  $ebayCredentials
     * @return array{
     *     analysis: array{items: list<array<string, mixed>>},
     *     modelCalls: int,
     *     searchesPerformed: int,
     *     audit: array{instructions: string, input: array<string, mixed>, events: list<array{sequence: int, type: string, title: string, data: mixed}>, rawResponses: list<mixed>, output: mixed, usage: mixed}
     * }
     *
     * @throws AnalysisFailed
     */
    public function run(#[\SensitiveParameter] string $apiKey, string $imageDataUrl, string $scanSessionId, string $findCriteria, ?array $ebayCredentials, Deadline $deadline): array
    {
        $input = [self::userMessage(self::buildInputText($findCriteria), $imageDataUrl)];
        $previousResponseId = null;
        $events = [];
        $rawResponses = [];
        $usage = ['requests' => 0, 'inputTokens' => 0, 'outputTokens' => 0, 'totalTokens' => 0, 'inputTokensDetails' => [], 'outputTokensDetails' => []];
        $searchesPerformed = 0;
        $repairRequested = false;

        for ($turn = 1; $turn <= self::MaxTurns; $turn++) {
            if ($deadline->expired()) {
                throw new AnalysisFailed(OpenAiResponses::TimedOutMessage);
            }

            $response = $this->createResponse($apiKey, $input, $previousResponseId, $ebayCredentials !== null, $deadline);
            $previousResponseId = $response['id'] ?? null;
            $output = is_array($response['output'] ?? null) ? $response['output'] : [];

            $rawResponses[] = AuditSanitizer::sanitize(['responseId' => $previousResponseId, 'usage' => $response['usage'] ?? null, 'output' => $output]);
            $usage = self::addUsage($usage, $response['usage'] ?? null);

            $functionCalls = [];
            $messageText = '';
            $refusal = null;

            foreach ($output as $item) {
                $type = $item['type'] ?? '';
                $events[] = self::event(count($events), $item);

                if (str_starts_with($type, 'web_search')) {
                    $searchesPerformed++;
                } elseif ($type === 'function_call') {
                    $functionCalls[] = $item;
                } elseif ($type === 'message') {
                    foreach ($item['content'] ?? [] as $content) {
                        if (($content['type'] ?? null) === 'output_text') {
                            $messageText .= $content['text'] ?? '';
                        } elseif (($content['type'] ?? null) === 'refusal') {
                            $refusal = $content['refusal'] ?? 'The model refused to analyze this frame.';
                        }
                    }
                }
            }

            if (($response['status'] ?? null) === 'failed') {
                throw new AnalysisFailed('Luna failed: '.($response['error']['message'] ?? 'unknown error'));
            }

            if ($functionCalls !== []) {
                $input = [];

                foreach ($functionCalls as $call) {
                    $result = $this->executeTool($call, $scanSessionId, $ebayCredentials, $deadline);

                    if (($call['name'] ?? null) === EbayListings::ToolName) {
                        $searchesPerformed++;
                    }

                    $events[] = [
                        'sequence' => count($events),
                        'type' => 'tool_call_output_item',
                        'title' => 'Tool result',
                        'data' => AuditSanitizer::sanitize([
                            'type' => 'tool_call_output_item',
                            'rawItem' => ['type' => 'function_call_result', 'name' => $call['name'] ?? null, 'callId' => $call['call_id'] ?? null, 'status' => 'completed'],
                            'output' => $result,
                        ]),
                    ];
                    $input[] = [
                        'type' => 'function_call_output',
                        'call_id' => $call['call_id'] ?? '',
                        'output' => is_string($result) ? $result : (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ];
                }

                continue;
            }

            if ($refusal !== null) {
                throw new AnalysisFailed("Luna refused to analyze this frame: {$refusal}");
            }

            if (trim($messageText) === '') {
                $reason = $response['incomplete_details']['reason'] ?? null;

                throw new AnalysisFailed($reason ? "Luna stopped before finishing ({$reason})." : 'Luna completed without structured output.');
            }

            try {
                $analysis = FrameAnalysisSchema::parse($messageText);
            } catch (InvalidArgumentException $exception) {
                if ($repairRequested || $turn === self::MaxTurns) {
                    throw new AnalysisFailed('Luna returned an invalid analysis. '.$exception->getMessage());
                }

                $repairRequested = true;
                $input = [[
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => $exception->getMessage().' Return the complete analysis again as valid JSON that matches the schema exactly.']],
                ]];

                continue;
            }

            return [
                'analysis' => $analysis,
                'modelCalls' => $usage['requests'],
                'searchesPerformed' => $searchesPerformed,
                'audit' => [
                    'instructions' => self::Instructions,
                    'input' => self::auditInput($findCriteria),
                    'events' => $events,
                    'rawResponses' => $rawResponses,
                    'output' => $analysis,
                    'usage' => $usage,
                ],
            ];
        }

        throw new AnalysisFailed('Max turns ('.self::MaxTurns.') exceeded');
    }

    /**
     * Request one model turn. Later turns chain on the previous response (stored server-side for the API's default
     * 30 days, as with the Agents SDK), so its reasoning items carry over without re-uploading the frame.
     *
     * @param  list<array<string, mixed>>  $input
     * @return array<string, mixed>
     */
    private function createResponse(#[\SensitiveParameter] string $apiKey, array $input, ?string $previousResponseId, bool $withEbay, Deadline $deadline): array
    {
        $payload = [
            'model' => self::Model,
            'instructions' => self::Instructions,
            'input' => $input,
            'tools' => array_values(array_filter([
                PreviousScans::toolDefinition(),
                self::WebSearchTool,
                $withEbay ? EbayListings::toolDefinition() : null,
            ])),
            'reasoning' => ['effort' => self::ReasoningEffort],
            'text' => ['verbosity' => self::TextVerbosity, 'format' => FrameAnalysisSchema::textFormat()],
        ];

        if ($previousResponseId !== null) {
            $payload['previous_response_id'] = $previousResponseId;
        }

        return $this->responses->create($apiKey, $payload, $deadline);
    }

    /**
     * @param  array<string, mixed>  $call
     * @param  array{clientId: string, clientSecret: string}|null  $ebayCredentials
     */
    private function executeTool(array $call, string $scanSessionId, ?array $ebayCredentials, Deadline $deadline): mixed
    {
        $name = (string) ($call['name'] ?? '');
        $available = $name === PreviousScans::ToolName || ($name === EbayListings::ToolName && $ebayCredentials !== null);

        if (! $available) {
            // The Agents SDK fails the run (ModelBehaviorError) when the model calls a tool the agent doesn't have.
            throw new AnalysisFailed("Luna called a tool that isn't available ({$name}).");
        }

        try {
            $arguments = json_decode((string) ($call['arguments'] ?? '{}'), true, 64, JSON_THROW_ON_ERROR);

            return $name === PreviousScans::ToolName
                ? $this->previousScans->check($scanSessionId, self::candidates($arguments))
                : $this->ebay->search(
                    $ebayCredentials,
                    self::ebayQuery($arguments),
                    max(1, min(20, (int) ($arguments['limit'] ?? 8))),
                    $deadline->cap(EbayListings::RequestTimeoutSeconds),
                );
        } catch (Throwable $exception) {
            return 'An error occurred while running the tool. Please try again. Error: '.$exception->getMessage();
        }
    }

    /**
     * @return list<array{fingerprint: string, name: string, category: string, brand: ?string, model: ?string, description: string}>
     */
    private static function candidates(mixed $arguments): array
    {
        $candidates = is_array($arguments) ? ($arguments['candidates'] ?? null) : null;

        if (! is_array($candidates) || ! array_is_list($candidates) || count($candidates) < 1 || count($candidates) > 20) {
            throw new InvalidArgumentException('candidates must be a list of 1 to 20 items.');
        }

        return array_map(fn (mixed $candidate): array => [
            'fingerprint' => (string) ($candidate['fingerprint'] ?? ''),
            'name' => (string) ($candidate['name'] ?? ''),
            'category' => (string) ($candidate['category'] ?? ''),
            'brand' => isset($candidate['brand']) ? (string) $candidate['brand'] : null,
            'model' => isset($candidate['model']) ? (string) $candidate['model'] : null,
            'description' => (string) ($candidate['description'] ?? ''),
        ], $candidates);
    }

    private static function ebayQuery(mixed $arguments): string
    {
        $query = is_array($arguments) ? ($arguments['query'] ?? null) : null;

        if (! is_string($query) || mb_strlen($query) < 2 || mb_strlen($query) > 300) {
            throw new InvalidArgumentException('query must be 2 to 300 characters.');
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private static function userMessage(string $text, string $image): array
    {
        return [
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => $text],
                ['type' => 'input_image', 'image_url' => $image, 'detail' => 'high'],
            ],
        ];
    }

    /**
     * An audit event for one model output item, typed and titled like the Agents SDK run items.
     *
     * @param  array<string, mixed>  $item
     * @return array{sequence: int, type: string, title: string, data: mixed}
     */
    private static function event(int $sequence, array $item): array
    {
        $itemType = (string) ($item['type'] ?? 'unknown');
        $type = match (true) {
            $itemType === 'message' => 'message_output_item',
            $itemType === 'reasoning' => 'reasoning_item',
            $itemType === 'function_call', str_ends_with($itemType, '_call') => 'tool_call_item',
            default => $itemType,
        };
        $toolName = $itemType === 'function_call' ? ($item['name'] ?? null) : (str_ends_with($itemType, '_call') ? $itemType : null);

        $title = match (true) {
            is_string($toolName) && str_starts_with($toolName, 'web_search') => 'Web search',
            $type === 'tool_call_item' => $toolName ? "Tool call · {$toolName}" : 'Tool call',
            $type === 'reasoning_item' => 'Reasoning summary',
            $type === 'message_output_item' => 'Assistant response',
            default => str_replace('_', ' ', $type),
        };

        return [
            'sequence' => $sequence,
            'type' => $type,
            'title' => $title,
            'data' => AuditSanitizer::sanitize(['type' => $type, 'rawItem' => $item]),
        ];
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array<string, mixed>
     */
    private static function addUsage(array $usage, mixed $responseUsage): array
    {
        $usage['requests']++;

        if (! is_array($responseUsage)) {
            return $usage;
        }

        $usage['inputTokens'] += (int) ($responseUsage['input_tokens'] ?? 0);
        $usage['outputTokens'] += (int) ($responseUsage['output_tokens'] ?? 0);
        $usage['totalTokens'] += (int) ($responseUsage['total_tokens'] ?? 0);

        foreach (['input_tokens_details' => 'inputTokensDetails', 'output_tokens_details' => 'outputTokensDetails'] as $source => $target) {
            foreach ((array) ($responseUsage[$source] ?? []) as $key => $count) {
                if (is_numeric($count)) {
                    $usage[$target][$key] = ($usage[$target][$key] ?? 0) + (int) $count;
                }
            }
        }

        return $usage;
    }
}
