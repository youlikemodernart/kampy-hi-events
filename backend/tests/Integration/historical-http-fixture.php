<?php

// CLI setup and loopback-only test router; not loaded by application routes.
use Carbon\Carbon;
use HiEvents\Repository\Eloquent\HistoricalReceiptRecoveryRepository as Recovery;
use HiEvents\Repository\Eloquent\RespondentConfirmationRepository as Challenges;
use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgeService as Bridge;
use HiEvents\Services\Domain\Registration\RespondentConfirmationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\HistoricalReceiptFixture as Historical;
use Tests\Support\RespondentConfirmationFixture as Fixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__, 2).'/public/index.php';
$app->instance('request', Illuminate\Http\Request::capture());
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
Fixture::configure();
$peer = getenv('HISTORICAL_PORTAL_ORIGIN');
$clock = getenv('HISTORICAL_CLOCK_FILE');
if (! preg_match('~\Ahttp://127\.0\.0\.1:[0-9]+\z~', $peer) || ! is_file($clock)) {
    throw new RuntimeException('Owned loopback peer and clock required');
}
Carbon::setTestNow(trim(file_get_contents($clock)));
[$manifest, $rows] = Historical::bundle();
Historical::configure($manifest);
config()->set('services.gvsu_registration_bridge.portal_host', 'portal.example.test');
config()->set('services.gvsu_registration_bridge.outgoing_bearer', str_repeat('b', 43));
config()->set('services.gvsu_registration_bridge.incoming_current_digest', hash('sha256', str_repeat('b', 43)));
// URI-only transport seam: request/response bodies and both peers' authentication stay real.
Http::globalRequestMiddleware(function ($request) use ($peer) {
    if ($request->getUri()->getHost() !== 'portal.example.test') {
        throw new RuntimeException('Unexpected outbound request');
    }

    return $request->withUri(new GuzzleHttp\Psr7\Uri($peer.$request->getUri()->getPath()));
});
if (PHP_SAPI === 'cli') {
    switch ($argv[1] ?? '') {
        case 'seed':
            Fixture::reset();
            Historical::prepareNative($rows);
            Schema::table('events', function (Blueprint $t) {
                $t->timestamp('start_date')->nullable();
                $t->string('timezone')->default('America/New_York');
            });
            DB::table('events')->update(['start_date' => '2026-10-17 16:00:00']);
            $repo = new Recovery;
            $repo->import($manifest, $rows, fn ($id) => $repo->preflight($id), true);
            $challenges = new Challenges;
            $code = $challenges->issue('order_11')->token;
            if (! $challenges->verify('order_11', $code) || ! app(RespondentConfirmationService::class)->confirm('order_11', $code, Fixture::payload())) {
                throw new RuntimeException('Synthetic confirmation failed');
            }
            echo "seeded\n";
            break;
        case 'provision':
            if (! app(Bridge::class)->provisionCompletedOrder(11)) {
                throw new RuntimeException('Provision failed');
            }
            echo "provisioned\n";
            break;
        case 'revoke':
            DB::table('historical_receipt_recovery_cohorts')->update(['revoked_at' => now()]);
            echo "revoked\n";
            break;
        default:
            throw new RuntimeException('Unknown fixture action');
    }

    return;
}
if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/api/internal/gvsu-registration/current-state') {
    http_response_code(404);

    return;
}
// Production ingress strips /api before Laravel route dispatch.
$_SERVER['REQUEST_URI'] = substr($_SERVER['REQUEST_URI'], 4);
$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
