<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Chat;

use Bitenxt\SupportAgent\Agent\SupportTools;
use Bitenxt\SupportAgent\Knowledge\KnowledgeBase;

/**
 * Answers the questions customers ask most without calling the AI: orders,
 * patients, follow-ups, coupons, cart, catalog and products, "talk to
 * support", greetings, and how-to questions a help article clearly answers.
 * Replies are fixed templates filled from the same tools (and the same
 * ownership checks) the AI uses, so they are instant and cost no tokens.
 *
 * Anything that needs judgement (cancel, change, why, several requests in one
 * message, unusual wording...) returns null and goes to the AI, or to a menu
 * when the AI is switched off.
 *
 * Each answer comes with quick replies (tap-to-send buttons) for the likely
 * next step.
 */
final class FastPath
{
    public const MENU = ['My recent orders', 'How many orders do I have?', 'List my patients', 'Any coupons for me?',
        "What's in my cart?", 'What products are available?', 'How do I place an order?', 'Talk to support'];

    private const MAX_LENGTH = 160;
    private const ORDER_NUMBER = '/(?<![\w-])#?(\d{3,12})(?![\w-])/';
    private const ORDER_WORDS = '/\b(status|track|tracking|where|order|update|shipped|dispatched|delivered)\b/i';
    /** Words that mean the customer wants something done or explained, not just looked up. */
    private const NEEDS_AI = '/\b(cancel|change|edit|modify|refund|remake|return|complain|complaint|wrong|damaged|why|how|when|'
        . 'note|notes|follow|invoice|address|reorder|again|patient|help|delay|delayed|late|person|agent|human|support|'
        . 'and|also|not|problem|issue|urgent)\b/i';
    private const NEEDS_ACTION = '/\b(cancel|refund|remake|return|complain|complaint|wrong|damaged|broken|urgent|problem|issue|'
        . 'not|never|late|delay|delayed|error|failed|unable|can\'?t)\b/i';

    private const RECENT_ORDERS = '/^\s*(show|list|see|view|check|get)?\s*(me\s+)?(all\s+)?(my\s+)?(recent|latest|last|past|previous)?\s*'
        . 'orders?(\s+(history|list|status))?\s*[?.!]*\s*$/i';
    private const ORDER_COUNT = '/^\s*(how\s+many|total|count|number\s+of)\b.*\borders?\b[^.]*[?.!]*\s*$/i';
    private const COUPONS = '/^\s*(are\s+there\s+|do\s+i\s+have\s+|any\s+|show\s+(me\s+)?|list\s+|my\s+|available\s+|what\s+)*'
        . '(active\s+|available\s+)?(coupons?|coupon\s+codes?|promo\s+codes?|discount\s+codes?|offers?|discounts?)'
        . '(\s+(available|for\s+me|do\s+i\s+have|are\s+there|now|today))*\s*[?.!]*\s*$/i';
    private const CART = '/^\s*(show\s+(me\s+)?|what\'?s\s+in\s+|what\s+is\s+in\s+|view\s+|check\s+)?(my\s+)?cart\s*[?.!]*\s*$/i';
    private const TRACK_NO_NUMBER = '/^\s*(track|trace|where\s+is|status\s+of|check)\s+(my\s+)?(an?\s+)?order\s*[?.!]*\s*$/i';
    private const PATIENT_LIST = '/^\s*((who\s+are|list|show|see|view)\s+(me\s+)?(all\s+)?)?(my\s+)?(all\s+)?patients?(\s+list)?\s*[?.!]*\s*$/i';
    private const FOLLOW_UPS = '/\b(follow[\s-]?ups?|notes?|comments?|updates?)\s+(on|for|of|in)\s+(the\s+)?(order\s+)?#?(\d{3,12})\b/i';
    private const CATALOG = '/^\s*((what|which)\s+(all\s+)?(products?|services?|items?)\b.*|(show|list|see)\s+(me\s+)?(all\s+)?'
        . '(the\s+)?(products?|services?|catalog(ue)?)|(products?|services?|catalog(ue)?)(\s+list)?|what\s+do\s+you\s+(offer|sell|make|provide))'
        . '\s*[?.!]*\s*$/i';
    private const PRODUCT_SEARCH = '/^\s*(do\s+you\s+(have|offer|make|sell|provide)|price\s+(of|for)|cost\s+of|search(\s+for)?|'
        . 'show\s+me|looking\s+for)\s+(an?\s+|any\s+|the\s+)?(?<q>[\p{L}][\p{L}\d \-]{1,48}?)\s*[?.!]*\s*$/iu';
    private const SHORT_PRODUCT = '/^\s*(?<q>[\p{L}][\p{L} \-]{2,30}?)\s*\?\s*$/u';
    /** "talk to support", "connect me to customer care", "I need a human", "raise a complaint", "call me back"... */
    private const SUPPORT_INTENT = '/\b(talk|speak|chat|connect|contact|reach|call|email|e-mail|mail|message|write|transfer|escalate|'
        . 'pass|forward|put\s+me\s+through)\b.{0,40}?\b(support|agent|human|person|someone|somebody|team|executive|'
        . 'customer\s+(care|service|support)|representative|staff|helpdesk|help\s*desk)\b|'
        . '\b(need|want|get)\s+(a\s+|to\s+talk\s+to\s+a\s+)?(human|real\s+person|live\s+agent|person)\b|'
        . '\b(raise|file|log|register|open|create)\s+(a\s+|an\s+)?(ticket|complaint|support\s+request|issue)\b|'
        . '\b(call\s+me(\s+back)?|callback|call\s+back|escalate)\b|'
        . '\b(can|could)\s+(someone|somebody|anyone|any\s+one)\s+(from\s+\w+\s+)?(help|call|assist|contact)\b|'
        . '^\s*(support|customer\s+(care|service|support)|help\s*desk|helpdesk|agent|human|live\s+agent|contact\s+us|'
        . 'need\s+help|help\s+me|support\s+please|please\s+help)\s*[?.!]*\s*$/i';
    /** Words that only express "connect me", so a message made only of these has no details yet. */
    private const SUPPORT_FILLER = ['talk', 'speak', 'chat', 'connect', 'contact', 'reach', 'call', 'email', 'e-mail', 'mail', 'message',
        'write', 'transfer', 'escalate', 'pass', 'forward', 'put', 'through', 'me', 'to', 'with', 'a', 'an', 'the', 'your', 'you',
        'someone', 'somebody', 'from', 'support', 'agent', 'human', 'person', 'team', 'executive', 'customer', 'care', 'service',
        'representative', 'staff', 'real', 'live', 'i', 'want', 'need', 'would', 'like', 'can', 'could', 'please', 'pls', 'plz',
        'kindly', 'now', 'immediately', 'urgently', 'urgent', 'asap', 'help', 'desk', 'helpdesk', 'raise', 'file', 'log',
        'register', 'open', 'create', 'ticket', 'complaint', 'request', 'issue', 'back', 'callback', 'get', 'be', 'able', 'let',
        'us', 'know', 'and', 'in', 'about', 'regarding', 'for', 'on', 're', 'my', 'this', 'that', 'it', 'is', 'am', 'just',
        'hi', 'hello', 'hey', 'ok', 'okay', 'yes', 'some', 'one', 'of', 'do', 'have', 'there', 'any', 'who', 'directly', 'send', 'go',
        'how', 'what', 'where', 'when', 'anyone', 'assist', 'guys', 'sir', 'madam', 'team\'s', 'will', 'should', 'does'];
    /** An order number in a support message: after "order" or "#", or a message that is only the number (amounts and phones are not orders). */
    private const SUPPORT_ORDER = '/\border\s*(?:number|no\.?|#)?\s*:?\s*#?(\d{3,12})\b|#(\d{3,12})\b|^\s*(\d{3,12})\s*[.!?]*\s*$/i';
    private const SUPPORT_CANCEL = '/^\s*(cancel|no|nope|no\s+thanks?|never\s*mind|nevermind|not\s+now|forget\s+it|stop)\s*[.!]*\s*$/i';
    private const JUST_CONNECT = 'Just connect me';
    private const GREETING = '/^\s*(hi+|hello+|hey+|hii+|good\s+(morning|afternoon|evening)|namaste|greetings)(\s+there)?\s*[!.?]*\s*$/i';
    private const THANKS = '/^\s*(thanks?(\s+you)?(\s+so\s+much)?|thank\s+you(\s+so\s+much)?|thx|ty|ok(ay)?|great|cool|got\s+it|perfect|'
        . 'that\'?s\s+all|bye|goodbye)\s*[!.]*\s*$/i';
    private const HOW_TO = '/^\s*(how|what|where|can|could|do|does|is|are|which|when)\b|\bhow\s+to\b/i';
    private const NOT_A_NAME = '/^(me|my|all|this|that|the|it|them|him|her|you|your|our|us|order|orders|patient|patients)$/i';

    public function __construct(
        private readonly ?KnowledgeBase $knowledge = null,
        private readonly string $supportPhone = '',
        private readonly string $supportEmail = '',
    ) {
    }

    /**
     * @return array{text: string, quick_replies: list<string>, kind: string}|null
     *         kind is "fast_path" or "article"; null when nothing here fits
     */
    public function answer(string $text, SupportTools $tools): ?array
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > self::MAX_LENGTH) {
            // A long message right after "what do you need help with?" is the support request itself.
            $support = $tools->awaitingSupportDetails() ? $this->support($text, $tools) : null;

            return $support === null ? null : $support + ['kind' => 'fast_path'];
        }

        $reply = $this->conversation($text, $tools)
            ?? $this->lookups($text, $tools)
            ?? $this->orderStatusIfAsked($text, $tools);
        if ($reply !== null) {
            return $reply + ['kind' => 'fast_path'];
        }

        $article = $this->article($text);

        return $article === null ? null : $article + ['kind' => 'article'];
    }

    /** Greetings, thanks and "talk to support". */
    private function conversation(string $text, SupportTools $tools): ?array
    {
        if (preg_match(self::GREETING, $text)) {
            return self::reply("Hi! I can help with your orders, patients, cart, coupons and how to use BiteNXT Pro.\nTap a topic or type your question.", self::MENU);
        }
        if (preg_match(self::THANKS, $text)) {
            return self::reply("You're welcome! Anything else I can help with?", ['My recent orders', 'Talk to support']);
        }
        $support = $this->support($text, $tools);
        if ($support !== null) {
            return $support;
        }
        if (preg_match(self::TRACK_NO_NUMBER, $text)) {
            return self::reply('Sure! Send me the order number (for example 671), or pick one from your recent orders.', ['My recent orders']);
        }

        return null;
    }

    /**
     * "Talk to support" and every other way of asking for a person. A request
     * with details is sent straight away; a bare one first asks what it is
     * about, so the team gets the customer's actual request.
     */
    private function support(string $text, SupportTools $tools): ?array
    {
        if ($tools->awaitingSupportDetails()) {
            $tools->setAwaitingSupportDetails(false);
            if (preg_match(self::SUPPORT_CANCEL, $text)) {
                return self::reply('No problem, I won\'t contact the team. Anything else I can help with?', self::MENU);
            }
            if (!in_array($text, self::MENU, true) || $text === 'Talk to support') {
                // Whatever they type now is the request ("Just connect me" sends it without details).
                return $this->sendToSupport($text, self::hasSupportDetails($text, 1) ? $text : '', $tools);
            }
            // They tapped another topic instead: answer that.
            return null;
        }
        if (!preg_match(self::SUPPORT_INTENT, $text)) {
            return null;
        }
        if (self::hasSupportDetails($text, 1)) {
            return $this->sendToSupport($text, $text, $tools);
        }

        $tools->setAwaitingSupportDetails(true);
        $order = $tools->lastOrder();
        $short = ltrim($order, '0');

        return self::reply(
            "Sure, I'll connect you with our support team. What do you need help with?\n"
            . 'Tell me in a sentence (for example "change the shade on order ' . ($short !== '' ? $short : '729') . '") and I\'ll send it to them.',
            [$order !== '' ? "About order {$short}" : '', self::JUST_CONNECT, 'Cancel'],
        );
    }

    /** @return array{text: string, quick_replies: list<string>}|null */
    private function sendToSupport(string $text, string $request, SupportTools $tools): ?array
    {
        $order = '';
        if (preg_match(self::SUPPORT_ORDER, $text, $m)) {
            $number = implode('', array_slice($m, 1)); // whichever group matched
            // Confirm the order is theirs first, so the email can name it.
            $check = self::run($tools, 'get_order_status', ['order_number' => $number]);
            if (isset($check['error'])) {
                return self::errorReply($check['error'], $number);
            }
            $order = (string) ($check['order']['order_number'] ?? '');
        }
        if ($request === '' && $order !== '') {
            $request = "Help with order {$order}";
        }

        $result = self::run($tools, 'escalate_to_human', [
            'reason' => 'customer_requested',
            'summary' => 'The customer asked in chat to talk to the support team'
                . ($order !== '' ? " about order {$order}" : '') . ($request !== '' ? ': "' . mb_substr($request, 0, 300) . '"' : '.'),
            'order_number' => $order,
            'urgency' => preg_match('/\b(urgent|urgently|asap|immediately|emergency)\b/i', $text) ? 'high' : 'normal',
            'request' => $request,
        ]);
        $status = $result['status'] ?? '';
        $to = ($result['reply_to'] ?? '') !== '' ? " ({$result['reply_to']})" : '';
        $about = $order !== '' ? " about order {$order}" : '';
        $lines = match ($status) {
            'escalated' => ["I've passed your request{$about} to our support team. They'll reply to your registered email{$to}."],
            'already_escalated' => ["You've already sent this request{$about}, and our support team has it. They'll reply to your registered email{$to}."],
            'limit_reached' => ["Our support team already has your requests from this chat and will reply to your registered email{$to}."],
            default => null,
        };
        if ($lines === null) {
            // The request could not be sent: give the direct contacts instead of a promise.
            $contacts = array_filter([
                $this->supportPhone !== '' ? "call {$this->supportPhone}" : '',
                $this->supportEmail !== '' ? "email {$this->supportEmail}" : '',
            ]);

            return self::reply("Sorry, I couldn't reach the team from chat just now."
                . ($contacts !== [] ? ' Please ' . implode(' or ', $contacts) . ' and they will help you.' : ' Please try again shortly.'), ['My recent orders']);
        }
        if ($this->supportPhone !== '') {
            $lines[] = "If it's urgent, call us on {$this->supportPhone}.";
        }

        return self::reply(implode("\n", $lines), ['My recent orders']);
    }

    /** Whether a support message says what it is about (an order number, or a few words beyond "connect me"). */
    private static function hasSupportDetails(string $text, int $minWords = 1): bool
    {
        if ($text === self::JUST_CONNECT) {
            return false;
        }
        if (preg_match(self::SUPPORT_ORDER, $text)) {
            return true;
        }
        $words = preg_split('/[^\p{L}\p{N}\'-]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $left = array_diff($words, self::SUPPORT_FILLER);

        return count($left) >= $minWords;
    }

    /** Account lookups: orders, patients, follow-ups, coupons, cart, catalog and products. */
    private function lookups(string $text, SupportTools $tools): ?array
    {
        $withoutHowMany = (string) preg_replace('/\bhow\s+many\b/i', '', $text);
        $simple = !preg_match(self::NEEDS_ACTION, $text);

        if ($simple && preg_match(self::FOLLOW_UPS, $text, $m)) {
            return $this->followUps($m[5], $tools);
        }
        if ($simple && ($patient = $this->patientQuestion($text)) !== null) {
            return $this->patientOrders($patient['name'], $patient['mode'], $tools);
        }
        if ($simple && preg_match(self::PATIENT_LIST, $text)) {
            return $this->patients($tools);
        }
        if (preg_match(self::NEEDS_AI, $withoutHowMany)) {
            return null;
        }
        if (preg_match(self::RECENT_ORDERS, $text)) {
            return $this->recentOrders($tools);
        }
        if (preg_match(self::ORDER_COUNT, $text)) {
            return $this->orderCount($tools);
        }
        if (preg_match(self::COUPONS, $text)) {
            return $this->coupons($tools);
        }
        if (preg_match(self::CART, $text)) {
            return $this->cart($tools);
        }
        if (preg_match(self::CATALOG, $text)) {
            return $this->catalog($tools);
        }
        if (preg_match(self::PRODUCT_SEARCH, $text, $m) && !$this->isAccountWord($m['q'])) {
            return $this->products(trim($m['q']), $tools, true);
        }
        if (preg_match(self::SHORT_PRODUCT, $text, $m) && !$this->isAccountWord($m['q'])
            && !preg_match(self::HOW_TO, $text) && str_word_count($m['q']) <= 3) {
            return $this->products(trim($m['q']), $tools, false); // "Aligners?": only if something matches
        }

        return null;
    }

    private function orderStatusIfAsked(string $text, SupportTools $tools): ?array
    {
        if (preg_match(self::NEEDS_AI, (string) preg_replace('/\bhow\s+many\b/i', '', $text))) {
            return null;
        }
        preg_match_all(self::ORDER_NUMBER, $text, $numbers);
        if (count($numbers[1]) !== 1) {
            return null; // no number, or several: let the AI sort it out
        }
        $isBareNumber = preg_match('/^\s*#?\d{3,12}\s*[?.!]*\s*$/', $text) === 1;
        if (!$isBareNumber && !preg_match(self::ORDER_WORDS, $text)) {
            return null;
        }

        return $this->orderStatus($numbers[1][0], $tools);
    }

    /** A help article that clearly answers a how-to question, shown as written. */
    private function article(string $text): ?array
    {
        if ($this->knowledge === null || !preg_match(self::HOW_TO, $text) || preg_match(self::NEEDS_ACTION, $text)) {
            return null;
        }
        $best = $this->knowledge->bestMatch($text);
        // Most of the question's own words must be in the section, at least one in its heading.
        if ($best === null || $best['coverage'] < 0.75 || !$best['title_hit']) {
            return null;
        }

        return self::reply($best['title'] . "\n" . $best['body'], ['Talk to support', 'My recent orders']);
    }

    // ---- patients ------------------------------------------------------------

    /** @return array{name: string, mode: string}|null mode: list | latest | count */
    private function patientQuestion(string $text): ?array
    {
        $name = '[\p{L}][\p{L} .\'-]{0,38}?[\p{L}.]';
        $patterns = [
            'count' => "/^\\s*how\\s+many\\s+orders\\s+(does|do|for|of|has)\\s+(the\\s+)?(patient\\s+)?(?<name>{$name})(\\s+(have|has|got))?\\s*[?.!]*\\s*$/iu",
            'latest' => "/\\b(latest|last|recent|newest|most\\s+recent)\\s+order\\s+(for|of)\\s+(the\\s+)?(patient\\s+)?(?<name>{$name})(\\s+patient)?\\s*[?.!]*\\s*$/iu",
            'list' => "/\\b(orders?|cases?)\\s+(for|of|related\\s+to|belonging\\s+to|with)\\s+(the\\s+)?(patient\\s+)?(?<name>{$name})(\\s+patient)?\\s*[?.!]*\\s*$/iu",
            'list2' => "/^\\s*(show\\s+(me\\s+)?)?(patient\\s+)?(?<name>{$name})'s\\s+orders?\\s*[?.!]*\\s*$/iu",
        ];
        foreach ($patterns as $mode => $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $candidate = trim($m['name']);
                if (!preg_match(self::NOT_A_NAME, $candidate) && !preg_match('/^(i|we|you|they)\b|\b(my|all|recent|latest)\b/i', $candidate)) {
                    return ['name' => $candidate, 'mode' => $mode === 'list2' ? 'list' : $mode];
                }
            }
        }

        return null;
    }

    private function patientOrders(string $name, string $mode, SupportTools $tools): ?array
    {
        // "Kalyan K." (the label the bot shows) is searched as "Kalyan".
        $name = trim((string) preg_replace('/\s+\p{L}\.$/u', '', $name));
        $result = self::run($tools, 'find_orders_by_patient', ['patient_name' => $name]);
        if (isset($result['error'])) {
            return self::errorReply($result['error'], '');
        }
        $orders = $result['orders'] ?? [];
        $label = $orders[0]['patient'] ?? $name;
        if ($orders === []) {
            return self::reply("I couldn't find any orders for a patient named \"{$name}\" on your account.", ['List my patients', 'My recent orders']);
        }
        if ($mode === 'count') {
            $n = count($orders);

            return self::reply(sprintf('%s has %s order%s.', $label, $n >= 10 ? 'at least 10' : (string) $n, $n === 1 ? '' : 's'),
                ['Latest order for ' . $label]);
        }
        if ($mode === 'latest') {
            return $this->orderStatus((string) $orders[0]['order_number'], $tools);
        }

        $lines = ["Orders for {$label} (newest first):"];
        foreach (array_slice($orders, 0, 5) as $order) {
            $lines[] = '• ' . implode(' · ', array_filter([
                $order['order_number'] ?? null,
                isset($order['status']) ? self::status($order['status']) : null,
                isset($order['placed_on']) ? self::date($order['placed_on']) : null,
            ]));
        }
        if (count($orders) > 5) {
            $lines[] = 'and more. Send an order number for its details.';
        }

        return self::reply(implode("\n", $lines), ['Status of order ' . ltrim((string) $orders[0]['order_number'], '0')]);
    }

    private function patients(SupportTools $tools): ?array
    {
        $result = self::run($tools, 'list_patients', ['name' => '']);
        if (isset($result['error'])) {
            return self::errorReply($result['error'], '');
        }
        $patients = array_values(array_filter(array_column($result['patients'] ?? [], 'patient')));
        if ($patients === []) {
            return self::reply("You don't have any patients yet. You can add them in BiteNXT Pro under Patients.", ['How do I add a patient?']);
        }
        $lines = ['Your patients:'];
        foreach (array_slice($patients, 0, 20) as $patient) {
            $lines[] = '• ' . $patient;
        }

        return self::reply(implode("\n", $lines), array_map(static fn ($p) => 'Orders for ' . $p, array_slice($patients, 0, 3)));
    }

    // ---- orders --------------------------------------------------------------

    private function orderStatus(string $number, SupportTools $tools): ?array
    {
        $result = self::run($tools, 'get_order_status', ['order_number' => $number]);
        if (isset($result['error'])) {
            return self::errorReply($result['error'], $number);
        }
        $order = $result['order'] ?? null;
        if (!is_array($order)) {
            return null;
        }

        $lines = [sprintf('Order %s: %s', $order['order_number'] ?? $number, self::status($order['status'] ?? 'Unknown'))];
        $placed = array_filter([
            isset($order['placed_on']) ? 'Placed on ' . self::date($order['placed_on']) : null,
            isset($order['patient']) ? 'Patient: ' . $order['patient'] : null,
        ]);
        if ($placed !== []) {
            $lines[] = implode(' · ', $placed);
        }
        if (!empty($order['items'])) {
            $lines[] = 'Items: ' . implode(', ', array_map(
                static fn (array $item) => ($item['product'] ?? 'Item') . (isset($item['qty_ordered']) ? ' × ' . $item['qty_ordered'] : ''),
                $order['items'],
            ));
        }
        $money = array_filter([
            isset($order['grand_total']) ? 'Total: ' . $order['grand_total'] : null,
            isset($order['shipping_method']) ? 'Shipping: ' . $order['shipping_method'] : null,
        ]);
        if ($money !== []) {
            $lines[] = implode(' · ', $money);
        }
        foreach ($order['tracking'] ?? [] as $track) {
            if (isset($track['tracking_number'])) {
                $lines[] = 'Tracking: ' . trim(($track['carrier'] ?? '') . ' ' . $track['tracking_number']);
            }
        }
        $short = ltrim((string) ($order['order_number'] ?? $number), '0');

        return self::reply(implode("\n", $lines), ["Follow-ups on order {$short}", 'Talk to support']);
    }

    private function followUps(string $number, SupportTools $tools): ?array
    {
        $result = self::run($tools, 'get_order_follow_ups', ['order_number' => $number]);
        if (isset($result['error'])) {
            return self::errorReply($result['error'], $number);
        }
        $order = $result['order_number'] ?? $number;
        $items = $result['follow_ups'] ?? [];
        if ($items === []) {
            return self::reply("There are no follow-ups or notes on order {$order} yet.", ['Status of order ' . ltrim((string) $order, '0')]);
        }
        $lines = ["Follow-ups on order {$order}:"];
        foreach (array_slice($items, 0, 8) as $item) {
            $lines[] = '• ' . implode(' · ', array_filter([
                isset($item['date']) ? self::date($item['date']) : null,
                $item['item_sku'] ?? null,
                isset($item['note']) ? mb_strimwidth((string) $item['note'], 0, 200, '…') : null,
            ]));
        }

        return self::reply(implode("\n", $lines), ['Status of order ' . ltrim((string) $order, '0')]);
    }

    private function recentOrders(SupportTools $tools): ?array
    {
        $result = self::run($tools, 'get_recent_orders', ['limit' => 5]);
        if (isset($result['error'])) {
            return self::errorReply($result['error'], '');
        }
        $orders = $result['orders'] ?? [];
        if ($orders === []) {
            return self::reply("I couldn't find any orders on your account yet.", ['How do I place an order?']);
        }

        $lines = ['Your recent orders:'];
        foreach ($orders as $order) {
            $lines[] = '• ' . implode(' · ', array_filter([
                $order['order_number'] ?? null,
                isset($order['status']) ? self::status($order['status']) : null,
                isset($order['placed_on']) ? self::date($order['placed_on']) : null,
                $order['patient'] ?? null,
            ]));
        }
        $lines[] = '';
        $lines[] = 'Send me an order number for its full details.';

        return self::reply(implode("\n", $lines), array_map(
            static fn ($o) => 'Status of order ' . ltrim((string) ($o['order_number'] ?? ''), '0'),
            array_slice($orders, 0, 2),
        ));
    }

    private function orderCount(SupportTools $tools): ?array
    {
        $result = self::run($tools, 'get_order_stats', ['status' => '']);
        if (isset($result['error'])) {
            return self::errorReply($result['error'], '');
        }
        $total = (int) ($result['total_orders'] ?? 0);
        if ($total === 0) {
            return self::reply("You don't have any orders yet.", ['How do I place an order?']);
        }
        $lines = [sprintf('You have %d order%s in total.', $total, $total === 1 ? '' : 's')];
        foreach ($result['by_status'] ?? [] as $status => $count) {
            $lines[] = "• {$status}: {$count}";
        }
        if (isset($result['note'])) {
            $lines[] = '(' . $result['note'] . ')';
        }

        return self::reply(implode("\n", $lines), ['My recent orders', 'List my patients']);
    }

    // ---- coupons, cart, catalog -------------------------------------------------

    private function coupons(SupportTools $tools): ?array
    {
        $result = self::run($tools, 'get_available_coupons', ['code' => '']);
        if (isset($result['error'])) {
            return self::errorReply($result['error'], '');
        }
        if (($result['coupons'] ?? []) === []) {
            return self::reply('There are no active coupons on your account right now.', ["What's in my cart?"]);
        }
        $lines = ['Coupons you can use:'];
        foreach ($result['coupons'] as $coupon) {
            $lines[] = '• ' . implode(' · ', array_filter([
                isset($coupon['code']) ? 'Code ' . $coupon['code'] : null,
                $coupon['name'] ?? null,
                $coupon['discount'] ?? null,
                isset($coupon['valid_until']) ? 'valid until ' . self::date($coupon['valid_until']) : null,
            ]));
        }
        $lines[] = '';
        $lines[] = 'Enter the code in your cart before checkout.';

        return self::reply(implode("\n", $lines), ["What's in my cart?"]);
    }

    private function cart(SupportTools $tools): ?array
    {
        $result = self::run($tools, 'get_cart_summary', ['include_items' => true]);
        if (isset($result['error'])) {
            return self::errorReply($result['error'], '');
        }
        $cart = $result['cart'] ?? null;
        if (!is_array($cart)) {
            return self::reply('Your cart is empty.', ['What products are available?', 'How do I place an order?']);
        }
        $lines = ['Your cart:'];
        foreach ($cart['items'] ?? [] as $item) {
            $lines[] = '• ' . ($item['product'] ?? 'Item') . (isset($item['qty']) ? ' × ' . $item['qty'] : '');
        }
        $details = array_filter([
            isset($cart['total']) ? 'Total: ' . $cart['total'] : null,
            isset($cart['coupons_applied']) ? 'Coupon: ' . implode(', ', $cart['coupons_applied']) : null,
        ]);
        if ($details !== []) {
            $lines[] = implode(' · ', $details);
        }
        $case = array_filter([
            isset($cart['patient']) ? 'Patient: ' . $cart['patient'] : null,
            isset($cart['doctor']) ? 'Doctor: ' . $cart['doctor'] : null,
        ]);
        if ($case !== []) {
            $lines[] = implode(' · ', $case);
        }
        if (isset($cart['scan_upload'])) {
            $scan = $cart['scan_upload'];
            $lines[] = 'Scan upload: ' . trim(ucfirst((string) ($scan['status'] ?? '')) . ' (' . ($scan['files'] ?? 0) . ' file'
                . (($scan['files'] ?? 0) === 1 ? '' : 's') . ')');
        }

        return self::reply(implode("\n", $lines), ['Any coupons for me?', 'How do I upload a scan?']);
    }

    private function catalog(SupportTools $tools): ?array
    {
        $result = self::run($tools, 'get_catalog_overview', ['category' => '']);
        if (isset($result['error'])) {
            return null; // let the AI or the menu handle it
        }
        $categories = $result['categories'] ?? [];
        if ($categories === []) {
            return null;
        }

        return self::reply($this->categoryLines('Here is what you can order in BiteNXT Pro:', $categories),
            ['How do I place an order?', "What's in my cart?"]);
    }

    private function products(string $query, SupportTools $tools, bool $explicit): ?array
    {
        $result = self::run($tools, 'search_products', ['query' => $query]);
        if (isset($result['error'])) {
            return null;
        }
        $products = $result['products'] ?? [];
        if ($products === []) {
            if (!$explicit || ($result['categories'] ?? []) === []) {
                return null; // e.g. "Thanks?": not a product question after all
            }

            return self::reply($this->categoryLines("I couldn't find \"{$query}\" in the catalog. These are the categories you can order from:",
                $result['categories']), ['What products are available?', 'Talk to support']);
        }
        $lines = ["Products matching \"{$query}\":"];
        foreach ($products as $product) {
            $lines[] = '• ' . implode(' · ', array_filter([
                $product['name'] ?? null,
                $product['price'] ?? null,
                isset($product['in_stock']) ? ($product['in_stock'] ? 'available' : 'currently unavailable') : null,
            ]));
        }

        return self::reply(implode("\n", $lines), ['How do I place an order?']);
    }

    /** @param list<array<string, mixed>> $categories */
    private function categoryLines(string $intro, array $categories): string
    {
        $lines = [$intro];
        foreach (array_slice($categories, 0, 12) as $category) {
            $examples = array_slice($category['products'] ?? [], 0, 4);
            $lines[] = '• ' . ($category['category'] ?? 'Category') . ($examples !== [] ? ': ' . implode(', ', $examples) : '');
        }

        return implode("\n", $lines);
    }

    // ---- helpers -------------------------------------------------------------

    private function isAccountWord(string $q): bool
    {
        return preg_match('/\b(order|orders|cart|coupon|coupons|patient|patients|account|password|login|scan|scans|support|help|you|me|it|this|that)\b/i', $q) === 1;
    }

    /** @param list<string> $quickReplies */
    private static function reply(string $text, array $quickReplies): array
    {
        return ['text' => $text, 'quick_replies' => array_values(array_unique(array_filter($quickReplies)))];
    }

    /** @return array<string, mixed> */
    private static function run(SupportTools $tools, string $tool, array $input): array
    {
        [$json] = $tools->execute($tool, $input);

        return json_decode($json, true) ?: ['error' => 'invalid'];
    }

    private static function errorReply(string $error, string $number): ?array
    {
        $text = match ($error) {
            'not_found' => "I couldn't find order {$number} on your account. Please check the number and try again.",
            'too_many_attempts' => "I couldn't find those orders on your account. If you need help, ask me to connect you with our support team.",
            'session_expired', 'not_signed_in' => 'Your login has expired. Please sign in to BiteNXT Pro again.',
            'temporarily_unavailable' => "I can't load that right now. Please try again in a few minutes.",
            default => null, // unexpected: let the AI handle it
        };

        return $text === null ? null : self::reply($text, $error === 'not_found' ? ['My recent orders'] : ['Talk to support']);
    }

    private static function status(string $status): string
    {
        return ucfirst(str_replace('_', ' ', $status));
    }

    private static function date(string $value): string
    {
        $time = strtotime($value);

        return $time === false ? $value : date('j M Y', $time);
    }
}
