<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * Versand über SMTP (transaktionaler Dienst mit EU-Datenhaltung oder Hoster-SMTP) – bewusst kein PHP-mail().
 * Transport "datei" schreibt .eml-Dateien (nur für Tests/Abnahme ohne echten Versand).
 */
final class Mailer
{
    public function __construct(private array $cfg) {}

    /**
     * @param array{an:string|array,cc?:array,von:string,von_name:string,antwort_an?:string,antwort_an_name?:string,
     *              betreff:string,html:string,text:string,anhaenge?:array<int,array{name:string,inhalt:string,mime:string}>} $m
     */
    public function senden(array $m): void
    {
        $mail = new PHPMailer(true);
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
        $mail->XMailer = ' ';
        $mail->setFrom($m['von'], $m['von_name']);
        foreach ((array)$m['an'] as $an) $mail->addAddress($an);
        foreach ($m['cc'] ?? [] as $cc) $mail->addCC($cc);
        if (!empty($m['antwort_an'])) $mail->addReplyTo($m['antwort_an'], $m['antwort_an_name'] ?? '');
        $mail->Subject = $m['betreff'];
        $mail->isHTML(true);
        $mail->Body = $m['html'];
        $mail->AltBody = $m['text'];
        foreach ($m['anhaenge'] ?? [] as $a) $mail->addStringAttachment($a['inhalt'], $a['name'], PHPMailer::ENCODING_BASE64, $a['mime']);

        if (($this->cfg['transport'] ?? 'smtp') === 'datei') {
            $mail->preSend();
            $dir = $this->cfg['datei_transport_dir'];
            if (!is_dir($dir)) mkdir($dir, 0700, true);
            file_put_contents(sprintf('%s/%s-%s.eml', $dir, gmdate('Ymd-His'), bin2hex(random_bytes(3))), $mail->getSentMIMEMessage());
            return;
        }

        $s = $this->cfg['smtp'];
        $mail->isSMTP();
        $mail->Host = $s['host'];
        $mail->Port = (int)$s['port'];
        $mail->SMTPAuth = !empty($s['user']);
        $mail->Username = $s['user'] ?? '';
        $mail->Password = $s['pass'] ?? '';
        $mail->SMTPSecure = ($s['secure'] ?? 'tls') === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout = 15;
        if (!empty($s['dkim_domain']) && !empty($s['dkim_private_key'])) {
            // Nur nötig, wenn der SMTP-Dienst nicht selbst DKIM signiert.
            $mail->DKIM_domain = $s['dkim_domain'];
            $mail->DKIM_private = $s['dkim_private_key'];
            $mail->DKIM_selector = $s['dkim_selector'] ?? 'default';
        }
        $mail->send();
    }

    /** Sendet mit bis zu 3 Wiederholungen (Backoff 1 s, 3 s, 6 s). Gibt die letzte Fehlermeldung zurück oder null. */
    public function mitWiederholung(array $m, int $wiederholungen = 3): ?string
    {
        $fehler = null;
        $pausen = [1, 3, 6];
        for ($i = 0; $i <= $wiederholungen; $i++) {
            try { $this->senden($m); return null; }
            catch (MailException | RuntimeException $e) {
                $fehler = $e->getMessage();
                if ($i < $wiederholungen) sleep($pausen[$i] ?? 6);
            }
        }
        return $fehler;
    }
}
