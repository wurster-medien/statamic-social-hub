<?php

namespace WursterMedien\SocialHub\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use WursterMedien\SocialHub\Posts\PostStatusWriter;
use WursterMedien\SocialHub\Webhooks\SignatureVerifier;

/**
 * POST /!/social-hub/webhook
 *
 * Nimmt Statusmeldungen des Hubs entgegen (post.updated, post.published,
 * post.failed) und schreibt Status und Permalinks in den Eintrag.
 */
class WebhookController extends Controller
{
    public const EVENTS = ['post.updated', 'post.published', 'post.failed'];

    public function __invoke(Request $request, SignatureVerifier $verifier, PostStatusWriter $writer): JsonResponse
    {
        $payload = $request->getContent();

        if (! $verifier->verify($payload, $request->header(SignatureVerifier::HEADER), config('social-hub.webhook_secret'))) {
            Log::warning('[Social Hub] Webhook mit ungültiger oder abgelaufener Signatur abgelehnt.');

            return response()->json(['message' => 'Ungültige Signatur.'], 401);
        }

        $data = json_decode($payload, true);
        $event = is_array($data) ? ($data['event'] ?? null) : null;
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
}
