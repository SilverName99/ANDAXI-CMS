<?php

declare(strict_types=1);

namespace App\Support\ImportWordPress\MailPoet;

use App\Support\ImportWordPress\EroareImport;
use App\Support\ImportWordPress\SursaWordPress;

/**
 * Tabelele MailPoet ale bazei WordPress (`<prefix>mailpoet_*`, forma versiunii 5.45.2; specificația mailpoet §1), DOAR CITITE, prin
 * SursaWordPress (tranzacția `READ ONLY`, aceeași imagine a datelor pentru tot importul). Plus, opțional, exportul MailPoet (PLAN §7.1,
 * pista M): randările emailurilor fără corp păstrat și emailul de confirmare, din JSON-ul scris de exportator în `site/privat/`.
 *
 * Datele MailPoet sunt ora UTC (MailPoet scrie din PHP, pe care WordPress îl ține în UTC), citite în sesiunea implicită a bazei, ca
 * WordPress: `utc()` le trece în fusul site-ului nou (APP_TIMEZONE). Nimic de aici nu ajunge în ieșirea scriptului: doar numerele.
 */
final class SursaMailPoet
{
    /**
     * Formatul exportului: `{"format": FORMAT_EXPORT, "newslettere": {"<id>": {"html", "text", "partajare"}}, "confirmare": {"subiect",
     * "html", "text"}}`. `html` și `text`: randarea cu codurile pe loc a unui email fără corp păstrat (sau al unei automatizări);
     * `partajare`: identificatorul adresei publice a unei campanii partajabile (`<hash>-<slug>`, Url::getPublicShareIdentifier al lui
     * MailPoet, doar când ShareVisibility::canShare), altfel lipsă. Fiecare cheie e opțională.
     */
    public const FORMAT_EXPORT = 'andaxi-mailpoet-export/1';

    /** Mărimea maximă a exportului citit (randările tuturor emailurilor). */
    public const MAX_EXPORT = 64 * 1048576;

    /** @var array<string, mixed>|null */
    private ?array $setari = null;

    /** @var array<string, mixed>|null exportul (null = lipsă) */
    private ?array $export;

    public function __construct(public readonly SursaWordPress $s, ?string $fisierExport = null)
    {
        $this->export = $fisierExport !== null ? self::citesteExport($fisierExport) : null;
    }

    /** Are baza WordPress tabelele MailPoet (abonații și setările)? */
    public static function exista(SursaWordPress $s): bool
    {
        return $s->areTabel('mailpoet_subscribers') && $s->areTabel('mailpoet_settings') && $s->areTabel('mailpoet_segments');
    }

    public function areExport(): bool
    {
        return $this->export !== null;
    }

    /** @return array<string, mixed>|null randarea exportată a unui email (html, text), sau null */
    public function randare(int $newsletter): ?array
    {
        $r = $this->export['newslettere'][(string) $newsletter] ?? null;
        return is_array($r) && is_string($r['html'] ?? null) ? ['html' => (string) $r['html'], 'text' => is_string($r['text'] ?? null) ? (string) $r['text'] : ''] : null;
    }

    /**
     * Identificatorul adresei publice de partajare a campaniei (`/mailpoet-email/<hash>-<slug>/`), din export: doar în forma lui MailPoet
     * (12 hexa, apoi slug-ul) și doar cu hash-ul campaniei; altfel null (campania nu se partajează, sau exportul nu o spune).
     */
    public function partajare(int $newsletter, string $hash): ?string
    {
        $v = $this->export['newslettere'][(string) $newsletter]['partajare'] ?? null;
        if (!is_string($v) || preg_match('#^(?P<hash>[0-9a-f]{12})-[^/\s"<>]{1,200}$#D', $v, $m) !== 1 || $m['hash'] !== $hash) {
            return null;
        }
        return $v;
    }

    /** @return array{subiect: string, html: string, text: string}|null emailul de confirmare exportat (randat, cu codurile pe loc) */
    public function confirmare(): ?array
    {
        $c = $this->export['confirmare'] ?? null;
        if (!is_array($c) || !is_string($c['html'] ?? null) || !is_string($c['subiect'] ?? null)) {
            return null;
        }
        return ['subiect' => (string) $c['subiect'], 'html' => (string) $c['html'], 'text' => is_string($c['text'] ?? null) ? (string) $c['text'] : ''];
    }

    /** Setările MailPoet: un rând pe cheie de sus, tablourile serializate (desfăcute fără obiecte), scalarii ca text. @return array<string, mixed> */
    public function setari(): array
    {
        if ($this->setari === null) {
            $this->setari = [];
            foreach ($this->s->randuri('SELECT name, value FROM ' . $this->s->t('mailpoet_settings')) as $r) {
                $this->setari[(string) $r['name']] = SursaWordPress::desface((string) ($r['value'] ?? ''));
            }
        }
        return $this->setari;
    }

    /** O setare după calea cu puncte (`signup_confirmation.enabled`), sau $implicit. */
    public function setare(string $cale, mixed $implicit = null): mixed
    {
        $v = $this->setari();
        foreach (explode('.', $cale) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) {
                return $implicit;
            }
            $v = $v[$k];
        }
        return $v;
    }

    /** @return list<array<string, mixed>> */
    public function abonati(): array
    {
        $coloane = ['id', 'wp_user_id', 'is_woocommerce_user', 'first_name', 'last_name', 'email', 'status', 'subscribed_ip', 'confirmed_ip', 'confirmed_at',
            'last_subscribed_at', 'created_at', 'updated_at', 'deleted_at', 'source', 'link_token', 'last_engagement_at', 'email_count'];
        foreach (['last_sending_at', 'last_confirmation_email_sent_at', 'tracking_consent'] as $c) {
            if ($this->areColoana('mailpoet_subscribers', $c)) {
                $coloane[] = $c;
            }
        }
        return $this->s->randuri('SELECT ' . implode(', ', $coloane) . ' FROM ' . $this->s->t('mailpoet_subscribers') . ' ORDER BY id');
    }

    /** @return list<array<string, mixed>> */
    public function liste(): array
    {
        $extra = [];
        foreach (['display_in_manage_subscription_page', 'confirmation_email_id', 'confirmation_page_id'] as $c) {
            $extra[] = $this->areColoana('mailpoet_segments', $c) ? $c : 'NULL AS ' . $c;
        }
        return $this->s->randuri('SELECT id, name, type, description, created_at, deleted_at, ' . implode(', ', $extra) . ' FROM '
            . $this->s->t('mailpoet_segments') . ' ORDER BY id');
    }

    /** @return list<array<string, mixed>> */
    public function apartenente(): array
    {
        return $this->s->randuri('SELECT subscriber_id, segment_id, status, created_at, updated_at FROM ' . $this->s->t('mailpoet_subscriber_segment')
            . ' ORDER BY subscriber_id, segment_id');
    }

    /** Emailurile (fără corpul editorului): campaniile standard trimise și emailurile automatizărilor. @return list<array<string, mixed>> */
    public function emailuri(): array
    {
        return $this->s->randuri('SELECT id, hash, subject, type, status, sent_at, created_at, deleted_at, '
            . ($this->areColoana('mailpoet_newsletters', 'wp_post_id') ? 'wp_post_id' : 'NULL AS wp_post_id') . ' FROM ' . $this->s->t('mailpoet_newsletters')
            . " WHERE type IN ('standard', 'automation', 'automation_transactional') ORDER BY id");
    }

    /** Titlul postării WordPress a unui email făcut cu editorul nou (numele campaniei), sau null. */
    public function titluPostare(int $id): ?string
    {
        $v = $this->s->valoare('SELECT post_title FROM ' . $this->s->t('posts') . ' WHERE ID = ?', [$id]);
        return is_string($v) && trim($v) !== '' ? $v : null;
    }

    /** O postare WordPress (pentru paginile MailPoet din setări): tipul, starea, numele. @return array<string, mixed>|null */
    public function postare(int $id): ?array
    {
        $r = $this->s->randuri('SELECT ID, post_type, post_status, post_name FROM ' . $this->s->t('posts') . ' WHERE ID = ?', [$id]);
        return $r[0] ?? null;
    }

    /** @return array<int, list<int>> newsletter → listele lui (newsletter_segment) */
    public function listeEmail(): array
    {
        $rez = [];
        if ($this->s->areTabel('mailpoet_newsletter_segment')) {
            foreach ($this->s->randuri('SELECT newsletter_id, segment_id FROM ' . $this->s->t('mailpoet_newsletter_segment') . ' ORDER BY id') as $r) {
                $rez[(int) $r['newsletter_id']][] = (int) $r['segment_id'];
            }
        }
        return $rez;
    }

    /**
     * Opțiunile emailurilor (`newsletter_option`): legătura unui email de automatizare cu pasul lui (`automationId`, `automationStepId`,
     * pentru importul automatizărilor, pista J), `shareVisibility` (partajarea se ia din export: Url::getPublicShareIdentifier).
     *
     * @return array<int, array<string, string>> newsletter → opțiunile lui
     */
    public function optiuniEmail(): array
    {
        $rez = [];
        if ($this->s->areTabel('mailpoet_newsletter_option') && $this->s->areTabel('mailpoet_newsletter_option_fields')) {
            foreach ($this->s->randuri('SELECT o.newsletter_id, f.name, o.value FROM ' . $this->s->t('mailpoet_newsletter_option') . ' o INNER JOIN '
                . $this->s->t('mailpoet_newsletter_option_fields') . ' f ON f.id = o.option_field_id') as $r) {
                $rez[(int) $r['newsletter_id']][(string) $r['name']] = (string) ($r['value'] ?? '');
            }
        }
        return $rez;
    }

    /** Cozile de trimitere (cu sarcina lor). @return list<array<string, mixed>> */
    public function cozi(): array
    {
        return $this->s->randuri('SELECT q.id, q.task_id, q.newsletter_id, q.newsletter_rendered_body, q.newsletter_rendered_subject, q.count_total, q.created_at,'
            . ' t.status AS task_status, t.type AS task_type, t.processed_at FROM ' . $this->s->t('mailpoet_sending_queues') . ' q LEFT JOIN '
            . $this->s->t('mailpoet_scheduled_tasks') . ' t ON t.id = q.task_id ORDER BY q.id');
    }

    /** Sarcinile de trimitere care încă rulează (status NULL, scheduled, paused). */
    public function trimiteriInCurs(): int
    {
        return $this->s->numar('mailpoet_scheduled_tasks', "type = 'sending' AND deleted_at IS NULL AND (status IS NULL OR status IN ('scheduled', 'paused'))");
    }

    /** @return list<array<string, mixed>> */
    public function destinatari(): array
    {
        return $this->s->randuri('SELECT task_id, subscriber_id, processed, failed, error FROM ' . $this->s->t('mailpoet_scheduled_task_subscribers')
            . ' ORDER BY task_id, subscriber_id');
    }

    /** @return list<array<string, mixed>> */
    public function linkuri(): array
    {
        return $this->s->randuri('SELECT id, newsletter_id, queue_id, url, hash FROM ' . $this->s->t('mailpoet_newsletter_links') . ' ORDER BY id');
    }

    /** @return list<array<string, mixed>> */
    public function trimise(): array
    {
        return $this->s->randuri('SELECT newsletter_id, subscriber_id, queue_id, sent_at FROM ' . $this->s->t('mailpoet_statistics_newsletters') . ' ORDER BY id');
    }

    /** @return list<array<string, mixed>> */
    public function deschideri(): array
    {
        return $this->s->randuri('SELECT newsletter_id, subscriber_id, queue_id, created_at FROM ' . $this->s->t('mailpoet_statistics_opens') . ' ORDER BY id');
    }

    /** @return list<array<string, mixed>> */
    public function clicuri(): array
    {
        return $this->s->randuri('SELECT newsletter_id, subscriber_id, queue_id, link_id, `count`, created_at FROM ' . $this->s->t('mailpoet_statistics_clicks')
            . ' ORDER BY id');
    }

    /** @return list<array<string, mixed>> */
    public function dezabonari(): array
    {
        $extra = [];
        foreach (['method', 'reason', 'reason_text', 'reason_submitted_at'] as $c) {
            $extra[] = $this->areColoana('mailpoet_statistics_unsubscribes', $c) ? $c : 'NULL AS ' . $c;
        }
        return $this->s->randuri('SELECT id, newsletter_id, subscriber_id, queue_id, created_at, source, ' . implode(', ', $extra) . ' FROM '
            . $this->s->t('mailpoet_statistics_unsubscribes') . ' ORDER BY id');
    }

    /** Câmpurile personalizate definite (orice câmp apare pe pagina de administrare a abonamentului). */
    public function campuriPersonalizate(): int
    {
        return $this->s->numar('mailpoet_custom_fields') + $this->s->numar('mailpoet_subscriber_custom_field');
    }

    /** Automatizările care nu sunt în coș, cu pașii versiunii curente (cel mai mare id). @return list<array{id: int, status: string, pasi: mixed}> */
    public function automatizari(): array
    {
        if (!$this->s->areTabel('mailpoet_automations') || !$this->s->areTabel('mailpoet_automation_versions')) {
            return [];
        }
        $rez = [];
        foreach ($this->s->randuri('SELECT a.id, a.status, (SELECT v.steps FROM ' . $this->s->t('mailpoet_automation_versions') . ' v WHERE v.automation_id = a.id'
            . ' ORDER BY v.id DESC LIMIT 1) AS steps FROM ' . $this->s->t('mailpoet_automations') . " a WHERE a.deleted_at IS NULL AND a.status <> 'trash' ORDER BY a.id") as $r) {
            $rez[] = ['id' => (int) $r['id'], 'status' => (string) $r['status'], 'pasi' => json_decode((string) ($r['steps'] ?? ''), true)];
        }
        return $rez;
    }

    /** O dată MailPoet (UTC) în fusul site-ului nou, `Y-m-d H:i:s`; null rămâne null. */
    /**
     * Momentul unei date MailPoet (UTC) ca timp unix, pentru coloanele `TIMESTAMP` ale site-ului nou (scrise cu FROM_UNIXTIME(), deci
     * aceeași clipă oricare ar fi fusul sesiunii: conexiunea CMS are un decalaj fix, al momentului scrierii, iar o oră locală de vară
     * scrisă iarna s-ar muta cu o oră). null pentru o dată goală sau zero.
     */
    public static function epoca(mixed $v): ?int
    {
        if ($v === null || $v === '' || str_starts_with((string) $v, '0000-00-00')) {
            return null;
        }
        try {
            return (new \DateTimeImmutable((string) $v, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * O dată MailPoet (UTC, cum o scrie PHP-ul WordPress) în ora locală a site-ului nou (APP_TIMEZONE, cu regulile ei de oră de vară pentru
     * fiecare valoare), pentru coloanele `DATETIME`. null pentru o dată goală sau zero.
     */
    public static function utc(mixed $v): ?string
    {
        if ($v === null || $v === '' || str_starts_with((string) $v, '0000-00-00')) {
            return null;
        }
        try {
            return (new \DateTimeImmutable((string) $v, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function areColoana(string $tabel, string $coloana): bool
    {
        return (int) $this->s->valoare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->s->prefix . $tabel, $coloana]) > 0;
    }

    /** @return array<string, mixed> */
    private static function citesteExport(string $fisier): array
    {
        if (!is_file($fisier) || is_link($fisier)) {
            throw new EroareImport('exportul MailPoet lipsește: ' . $fisier . ' (exportatorul, PLAN §7.1)');
        }
        if ((int) filesize($fisier) > self::MAX_EXPORT) {
            throw new EroareImport('exportul MailPoet e prea mare (peste ' . (self::MAX_EXPORT / 1048576) . ' MB)');
        }
        $v = json_decode((string) file_get_contents($fisier), true, 64);
        if (!is_array($v) || ($v['format'] ?? null) !== self::FORMAT_EXPORT) {
            throw new EroareImport('exportul MailPoet nu e în formatul ' . self::FORMAT_EXPORT);
        }
        return $v;
    }
}
