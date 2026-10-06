<?php

try {
    // Disposable test router only: never loaded by application routes or a production server.
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__, 2).'/public/index.php';
    $app->instance('request', Illuminate\Http\Request::capture());
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $kernel->bootstrap();
    Tests\Support\RespondentConfirmationFixture::configure();
    config()->set('respondent-confirmation.invitation_enabled', true);
    config()->set('respondent-confirmation.origin', getenv('RESPONDENT_BROWSER_ORIGIN'));
    $fullCheckout = getenv('RESPONDENT_FULL_CHECKOUT') === '1';
    if ($fullCheckout) {
        Illuminate\Support\Facades\Event::forget(HiEvents\Events\OrderStatusChangedEvent::class);
        Illuminate\Support\Facades\Queue::fake();
    }
    $mailbox = getenv('RESPONDENT_BROWSER_MAILBOX');
    if (! $mailbox || ! str_starts_with($mailbox, rtrim(sys_get_temp_dir(), '/').'/respondent-browser-')) {
        throw new RuntimeException('Disposable mailbox required');
    }
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    header('Cache-Control: no-store');
    if ($path === '/_fixture/reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($fullCheckout) {
            Tests\Support\RespondentCheckoutFixture::migrate();
            Tests\Support\RespondentCheckoutFixture::seed();
        } else {
            Tests\Support\RespondentConfirmationFixture::reset();
        }
        if (is_file($mailbox)) {
            unlink($mailbox);
        }
        echo '{}';

        return;
    }
    if ($fullCheckout && $path === '/_fixture/paid' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        Tests\Support\RespondentCheckoutFixture::paid(11);
        echo '{}';

        return;
    }
    if ($fullCheckout && $path === '/_fixture/respondents') {
        header('Content-Type: application/json');
        echo json_encode(Tests\Support\RespondentCheckoutFixture::respondents(11));

        return;
    }
    if ($path === '/_fixture/mailbox') {
        header('Content-Type: text/html');
        echo is_file($mailbox) ? file_get_contents($mailbox) : '<p>No mail</p>';

        return;
    }
    if ($path === '/_fixture/counts') {
        header('Content-Type: application/json');
        echo json_encode(['challenges' => Illuminate\Support\Facades\DB::table('respondent_confirmation_challenges')->count(), 'assignments' => Illuminate\Support\Facades\DB::table('gvsu_registration_assignments')->count(), 'outbox' => Illuminate\Support\Facades\DB::table('order_effect_outbox')->count(), 'consumed' => Illuminate\Support\Facades\DB::table('respondent_confirmation_challenges')->whereNotNull('consumed_at')->count()]);

        return;
    }
    Illuminate\Support\Facades\Event::listen(Illuminate\Mail\Events\MessageSent::class, function ($event) use ($mailbox) {
        if (! str_contains($event->message->getHtmlBody() ?? '', 'verification')) {
            return;
        }
        if ($event->message->getTo()[0]->getAddress() !== 'buyer@example.test') {
            throw new RuntimeException('Unexpected synthetic destination');
        }
        file_put_contents($mailbox, $event->message->getHtmlBody(), LOCK_EX);
        chmod($mailbox, 0600);
    });
    if (str_starts_with($_SERVER['REQUEST_URI'], '/api/registration/invitation')) {
        $_SERVER['REQUEST_URI'] = substr($_SERVER['REQUEST_URI'], 4);
    }
    $response = $kernel->handle(Illuminate\Http\Request::capture());
    $response->send();
    $kernel->terminate(Illuminate\Http\Request::capture(), $response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['exception' => get_class($e), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
}
