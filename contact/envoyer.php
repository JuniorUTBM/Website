<?php
// Contact form handler for the static site. The form in contact/index.html posts here;
// the only server-side code on the site. Sends one email, then redirects back.

declare(strict_types=1);

const RECIPIENT = 'contact@juniorutbm.fr';
const MAX_MESSAGE_LENGTH = 5000;
const PROFILS = ['entreprise' => 'Une entreprise', 'etudiant' => 'Un·e étudiant·e', 'autre' => 'Autre'];

// No `never` return type: keeps the file compatible with PHP 7.4+ hosts.
function redirect_with(string $status): void
{
    header('Location: ./?' . $status . '=1#formulaire', true, 303);
    exit;
}

// Strips CR/LF so user input can never inject extra mail headers.
function single_line(string $value, int $max = 200): string
{
    return mb_substr(trim(preg_replace('/[\r\n]+/', ' ', $value)), 0, $max);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST', true, 405);
    exit;
}

// Honeypot: humans never see this field, bots usually fill it. Pretend success.
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    redirect_with('envoye');
}

$profil = (string) ($_POST['profil'] ?? '');
$email = single_line((string) ($_POST['email'] ?? ''), 254);
$telephone = single_line((string) ($_POST['telephone'] ?? ''), 40);
$objet = single_line((string) ($_POST['objet'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));
$consentement = ($_POST['consentement'] ?? '') === 'oui';

$valid = isset(PROFILS[$profil])
    && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
    && $message !== ''
    && mb_strlen($message) <= MAX_MESSAGE_LENGTH
    && $consentement;

if (!$valid) {
    redirect_with('erreur');
}

$subject = 'Site web — ' . ($objet !== '' ? $objet : 'Nouveau message');
$body = implode("\n", [
    'Profil : ' . PROFILS[$profil],
    'E-mail : ' . $email,
    'Téléphone : ' . ($telephone !== '' ? $telephone : '—'),
    'Objet : ' . ($objet !== '' ? $objet : '—'),
    '',
    $message,
]);

$headers = [
    // From must be on the site's own domain or most providers reject/spam the mail;
    // the visitor's address goes in Reply-To so "Reply" reaches them.
    'From' => 'Site Junior UTBM <' . RECIPIENT . '>',
    'Reply-To' => $email,
    'Content-Type' => 'text/plain; charset=UTF-8',
];

// WordPress stays installed behind the static site (see github.com/JuniorUTBM/junior-static-deploy): reuse its mailer so any
// SMTP setup configured there applies. Fall back to PHP mail() when WordPress is absent.
$wp_load = dirname(__DIR__) . '/wp-load.php';
if (is_file($wp_load)) {
    require $wp_load;
    $wp_headers = array_map(fn ($name, $value) => "$name: $value", array_keys($headers), $headers);
    $sent = wp_mail(RECIPIENT, $subject, $body, $wp_headers);
} else {
    $sent = mail(RECIPIENT, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

redirect_with($sent ? 'envoye' : 'erreur');
