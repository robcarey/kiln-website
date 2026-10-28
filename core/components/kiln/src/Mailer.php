<?php

namespace KilnWeb;

use MODX\Revolution\Mail\modMail;
use MODX\Revolution\Mail\modPHPMailer;

/**
 * Every email the website sends goes through here: account codes, licence
 * keys, receipts. HTML from an email.* chunk, plain text derived from it.
 * Locally PHP's mail() lands in Kiln's Mailpit; on the server MODX's SMTP
 * settings point at Resend.
 */
final class Mailer
{
    public function __construct(private Kiln $kiln)
    {
    }

    public function send(string $to, string $subject, string $chunk, array $ph = []): bool
    {
        $modx = $this->kiln->modx;
        $html = (string) $modx->getChunk($chunk, $ph + [
            'site_url' => rtrim((string) $this->kiln->opt('site_url'), '/'),
            'theme_url' => rtrim((string) $this->kiln->opt('site_url'), '/') . $this->kiln->opt('kiln.theme_url', '/assets/themes/kiln/'),
            'support_email' => $this->kiln->opt('kiln.support_email', 'hello@kilndev.com'),
            'subject' => $subject,
        ]);
        $from = (string) $this->kiln->opt('kiln.mail_from', $this->kiln->opt('emailsender'));

        /** @var modPHPMailer $mail */
        $mail = $modx->getService('mail', modPHPMailer::class);
        $mail->set(modMail::MAIL_BODY, $html);
        $mail->set(modMail::MAIL_BODY_TEXT, $this->text($html));
        $mail->set(modMail::MAIL_FROM, $from);
        $mail->set(modMail::MAIL_FROM_NAME, (string) $this->kiln->opt('kiln.mail_from_name', 'Kiln'));
        $mail->set(modMail::MAIL_SENDER, $from);
        $mail->set(modMail::MAIL_SUBJECT, $subject);
        $mail->address('to', $to);
        $mail->address('reply-to', (string) $this->kiln->opt('kiln.support_email', $from));
        $mail->setHTML(true);
        $ok = $mail->send();
        if (!$ok) {
            $this->kiln->log("mail to $to failed: " . $mail->mailer->ErrorInfo);
        }
        $mail->reset();
        return $ok;
    }

    private function text(string $html): string
    {
        $html = preg_replace('#<(style|head)\b.*?</\1>#si', '', $html) ?? $html;
        $html = preg_replace('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#si', '$2 ($1)', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/h\d|/tr|/div|/li)\b[^>]*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $text)));
    }
}
