<?php

namespace WursterMedien\SocialHub\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use WursterMedien\SocialHub\Feeds\FeedRepository;
use WursterMedien\SocialHub\Posts\PostStatusWriter;
use WursterMedien\SocialHub\Support\HubConnection;
use WursterMedien\SocialHub\Webhooks\SignatureVerifier;

/**
 * POST /!/social-hub/webhook
 *
 * Nimmt Meldungen des Hubs entgegen:
 * - post.updated, post.published, post.failed: schreibt Status und
 *   Permalinks in den Eintrag.
 * - feed.updated ({account: handle}): Der Feed des Kontos hat sich geändert.
 *   Er wird neu geladen, nachdem die Antwort an den Hub verschickt ist
 *   (terminating), damit der Hub nicht auf das Spiegeln der Medien wartet.
 *
 * Ungültige oder abgelaufene Signatur: 401. Bereits angenommene Signatur
 * (Replay innerhalb der Toleranz): 200 mit duplicate=true, ohne Wirkung.
 */
class WebhookController extends Controller
{
    public const EVENTS = ['post.updated', 'post.published', 'post.failed', self::FEED_UPDATED];

    public const FEED_UPDATED = 'feed.updated';

    public function __invoke(Request $request, SignatureVerifier $verifier, PostStatusWriter $writer, HubConnection $connection, FeedRepository $feeds): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $verifier->validSignature($payload, $request->header(SignatureVerifier::HEADER), $connection->webhookSecret());

        if ($signature === null) {
            Log::warning('[Social Hub] Webhook mit ungültiger oder abgelaufener Signatur abgelehnt.');

            return response()->json(['message' => 'Ungültige Signatur.'], 401);
        }

        // Wiederholung einer bereits angenommenen Zustellung: 200 ohne Wirkung,
        // damit der Absender nicht erneut zustellt.
        if (! $verifier->claim($signature)) {
            Log::info('[Social Hub] Wiederholter Webhook ignoriert.');

            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        $data = json_decode($payload, true);
        $event = is_array($data) ? ($data['event'] ?? null) : null;

        if ($event === self::FEED_UPDATED) {
            return $this->feedUpdated($data, $feeds);
        }

        $post = is_array($data) && is_array($data['post'] ?? null) ? $data['post'] : null;

        if (! in_array($event, self::EVENTS, true) || $post === null || blank($post['id'] ?? null)) {
            return response()->json(['message' => 'Unbekanntes Ereignis oder Post fehlt.'], 422);
        }

        $entry = $writer->apply($post);

        return response()->json([
            'ok' => true,
            'matched' => $entry !== null,
            'entry' => $entry?->id(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function feedUpdated(array $data, FeedRepository $feeds): JsonResponse
    {
        $handle = $data['account'] ?? null;

        if (! is_string($handle) || blank($handle)) {
            return response()->json(['message' => 'Konto fehlt.'], 422);
        }

        app()->terminating(fn () => $feeds->refreshAfterChange($handle));

        return response()->json(['ok' => true, 'account' => $handle]);
    }
}
