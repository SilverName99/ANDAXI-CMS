<?php
/**
 * Marketing → Automatizări newsletter (pluginul automatizari; App\Http\Controllers\AutomatizariController::lista): fluxurile
 * site-ului, cu comutatorul pornit / oprit, numărătorile (Intrate / În procesare / Ieșite) și emailurile lor (trimise,
 * deschise, clicuri), acțiunile (Editează, Duplică, Șterge) și șabloanele pentru o automatizare nouă.
 *
 * @var bool $disponibil
 * @var list<array<string, mixed>> $automatizari
 * @var array<string, array{titlu: string, descriere: string, declansator: string}> $sabloane
 * @var bool $preluareCos
 * @var bool $emailClasicCos
 * @var string $cronUltima
 * @var string $csrf
 */
use App\Support\Automatizari\Catalog;
use App\Support\Automatizari\Flux;

$automatizari = is_array($automatizari ?? null) ? $automatizari : [];
$campCsrf = '<input type="hidden" name="_csrf" value="' . adm_e($csrf) . '">';
$procent = static fn (int $parte, int $tot): string => $tot > 0 ? (string) round($parte * 100 / $tot) . '%' : '—';
$descriereDeclansator = static function (array $a): string {
    $s = (array) ($a['setari'] ?? []);
    $text = Catalog::DECLANSATOARE[(string) $a['declansator']]['eticheta'] ?? (string) $a['declansator'];
    return match ((string) $a['declansator']) {
        'cos_abandonat' => $text . ', după ' . (int) ($s['dupa'] ?? 60) . (($s['unitate'] ?? 'minute') === 'ore' ? ' h' : ' min'),
        'client_inactiv' => 'Client fără comandă de ' . (int) ($s['zile'] ?? 90) . ' de zile',
        'comanda_finalizata' => $text . (!empty($s['doar_prima']) ? ' (doar prima comandă)' : ''),
        default => $text,
    };
};
?>
<style>
.aut-lista{display:flex;flex-direction:column;gap:12px;}
.aut-rand{display:grid;grid-template-columns:minmax(0,1.6fr) auto minmax(0,1.4fr) auto;gap:16px;align-items:center;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;}
.aut-rand__nume{display:flex;flex-direction:column;gap:3px;min-width:0;}
.aut-rand__nume a{font-weight:600;font-size:15px;color:#111827;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.aut-rand__nume a:hover{color:#1d4ed8;text-decoration:underline;}
.aut-rand__nume small{color:#6b7280;font-size:13px;}
.aut-numere{display:flex;gap:18px;font-size:12px;color:#6b7280;}
.aut-numere span{display:flex;flex-direction:column;align-items:center;min-width:56px;}
.aut-numere strong{font-size:16px;color:#111827;font-variant-numeric:tabular-nums;}
.aut-actiuni{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;}
.aut-actiuni form{margin:0;}
.aut-actiuni .btn{min-height:40px;}
.aut-comutator{display:flex;align-items:center;gap:8px;margin:0;}
.aut-comutator button{position:relative;width:46px;height:26px;border-radius:999px;border:none;background:#d1d5db;cursor:pointer;padding:0;flex-shrink:0;}
.aut-comutator button::after{content:"";position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:transform .18s ease;}
.aut-comutator button[aria-checked="true"]{background:#16a34a;}
.aut-comutator button[aria-checked="true"]::after{transform:translateX(20px);}
.aut-comutator button:focus-visible{outline:3px solid #93c5fd;outline-offset:2px;}
.aut-sabloane{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;}
.aut-sablon{margin:0;}
.aut-sablon button{width:100%;min-height:118px;text-align:left;display:flex;flex-direction:column;gap:6px;padding:14px;border:1px solid #e5e7eb;border-radius:12px;background:#fff;cursor:pointer;font:inherit;color:#1f2937;transition:border-color .15s ease,box-shadow .15s ease,transform .15s ease;}
.aut-sablon button:hover,.aut-sablon button:focus-visible{border-color:#1d4ed8;box-shadow:0 4px 14px rgba(29,78,216,.14);transform:translateY(-1px);outline:none;}
.aut-sablon strong{font-size:15px;}
.aut-sablon span{font-size:13px;color:#6b7280;line-height:1.45;}
.aut-sablon em{font-style:normal;font-size:11px;font-weight:700;color:#1d4ed8;text-transform:uppercase;letter-spacing:.06em;}
.aut-sectiune{margin-top:22px;}
.aut-sectiune h2{font-size:16px;margin:0 0 10px;}
@media (max-width:1100px){.aut-rand{grid-template-columns:1fr;}.aut-actiuni{justify-content:flex-start;}}
@media (prefers-reduced-motion:reduce){.aut-comutator button::after,.aut-sablon button{transition:none;}.aut-sablon button:hover{transform:none;}}
</style>
<?php
admin_componenta('antet-pagina', [
    'titlu' => 'Automatizări newsletter',
    'nivel' => 1,
    'subtitlu' => 'Emailuri care pleacă singure, pe un drum gândit dinainte: bun venit, coș abandonat, mulțumire după comandă, clienți inactivi.',
    'ajutor' => [
        'Fiecare automatizare pornește la un eveniment (de exemplu „Comanda e livrată”) și merge apoi pas cu pas: așteaptă, verifică o condiție, trimite un email, pune o etichetă.',
        'Intră doar abonații activi ai newsletterului. Cine se dezabonează iese din flux la următorul email; nimeni nu primește același email de două ori.',
        'Emailurile automatizărilor pleacă din același cron ca newsletterele (la câteva minute) și se numără în același buget de trimiteri.',
        'Intrate = câți au intrat în flux; În procesare = câți sunt acum pe drum (de exemplu într-o așteptare); Ieșite = câți au terminat sau au ieșit.',
    ],
]);
?>
<?php if (!$disponibil): ?>
<?php admin_componenta('mesaj', ['tip' => 'avertisment', 'titlu' => 'Tabelele automatizărilor lipsesc',
    'text' => 'Rulează migrările (scripts/migreaza.php --aplica). Până atunci nicio automatizare nu pornește.']); ?>
<?php else: ?>
<?php if ($cronUltima === '' || strtotime($cronUltima) < time() - 3600): ?>
<?php admin_componenta('mesaj', ['tip' => 'avertisment', 'titlu' => 'Cronul newsletterului nu a rulat în ultima oră',
    'text' => 'Automatizările pleacă din scripts/newsletter-campaigns.php (la 5 minute, ca newsletterele programate). Fără el, nimeni nu înaintează prin flux.']); ?>
<?php endif; ?>
<?php if ($preluareCos): ?>
<?php admin_componenta('mesaj', ['tip' => 'info', 'titlu' => 'Coșul abandonat: automatizarea preia abonații',
    'text' => 'Cât o automatizare „Coș abandonat” e pornită, coșurile abonaților activi primesc doar emailurile ei. '
        . ($emailClasicCos ? 'Emailul clasic de coș abandonat (Emailuri de comandă) rămâne doar pentru cei care nu sunt abonați.' : 'Emailul clasic de coș abandonat e oprit.')
        . ' Un coș luat de automatizare nu mai primește niciodată și emailul clasic.']); ?>
<?php endif; ?>

<?php if ($automatizari === []): ?>
<?php admin_componenta('stare-goala', ['titlu' => 'Nicio automatizare încă',
    'text' => 'Alege mai jos un șablon (bun venit, coș abandonat, mulțumire, clienți inactivi) sau un flux gol.']); ?>
<?php else: ?>
<div class="aut-lista" role="list" aria-label="Automatizările site-ului">
<?php foreach ($automatizari as $a): ?>
    <?php
    $id = (int) $a['id'];
    $stare = (string) $a['stare'];
    $n = (array) $a['numere'];
    $clasaStare = $stare === 'activa' ? 'ok' : ($stare === 'oprita' ? 'oprit' : 'neutru');
    ?>
    <div class="aut-rand" role="listitem">
        <div class="aut-rand__nume">
            <a href="/admin/automatizari/<?= $id ?>"><?= adm_e((string) $a['nume']) ?></a>
            <small><?= adm_e($descriereDeclansator($a)) ?> · <?= (int) $a['nr_pasi'] === 1 ? '1 pas' : (int) $a['nr_pasi'] . ' pași' ?>
                <?php if ((int) $n['trimise'] > 0): ?> · <?= (int) $n['trimise'] ?> emailuri, deschise <?= adm_e($procent((int) $n['deschise'], (int) $n['trimise'])) ?>, clic <?= adm_e($procent((int) $n['clicuri'], (int) $n['trimise'])) ?><?php endif; ?></small>
        </div>
        <form class="aut-comutator" method="post" action="/admin/automatizari/<?= $id ?>/stare">
            <?= $campCsrf ?>
            <input type="hidden" name="stare" value="<?= $stare === 'activa' ? 'oprita' : 'activa' ?>">
            <button type="submit" role="switch" aria-checked="<?= $stare === 'activa' ? 'true' : 'false' ?>"
                aria-label="<?= adm_e(($stare === 'activa' ? 'Oprește' : 'Pornește') . ' automatizarea ' . (string) $a['nume']) ?>"></button>
            <span class="adm-stare adm-stare--<?= $clasaStare ?>"><?= adm_e(Flux::STARI[$stare] ?? $stare) ?></span>
        </form>
        <div class="aut-numere" aria-label="Numărători">
            <span><strong><?= (int) $n['intrate'] ?></strong>Intrate</span>
            <span><strong><?= (int) $n['in_procesare'] ?></strong>În procesare</span>
            <span><strong><?= (int) $n['iesite'] ?></strong>Ieșite</span>
        </div>
        <div class="aut-actiuni">
            <a class="btn" href="/admin/automatizari/<?= $id ?>">Editează</a>
            <form method="post" action="/admin/automatizari/<?= $id ?>/duplica"><?= $campCsrf ?><button type="submit" class="btn btn-secondary">Duplică</button></form>
            <form method="post" action="/admin/automatizari/<?= $id ?>/sterge"
                onsubmit="return confirm('Ștergi automatizarea „<?= adm_e(addslashes((string) $a['nume'])) ?>”? Cine e acum în flux iese, iar numărătorile și emailurile ei se pierd. Codurile de reducere deja trimise rămân valabile.');">
                <?= $campCsrf ?><button type="submit" class="btn btn-danger">Șterge</button>
            </form>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<section class="aut-sectiune" aria-labelledby="aut-sabloane-titlu">
    <h2 id="aut-sabloane-titlu">Automatizare nouă</h2>
    <div class="aut-sabloane">
    <?php foreach ($sabloane as $cheie => $s): ?>
        <form class="aut-sablon" method="post" action="/admin/automatizari/noua">
            <?= $campCsrf ?>
            <input type="hidden" name="sablon" value="<?= adm_e((string) $cheie) ?>">
            <button type="submit">
                <em><?= adm_e(Catalog::DECLANSATOARE[$s['declansator']]['eticheta'] ?? '') ?></em>
                <strong><?= adm_e($s['titlu']) ?></strong>
                <span><?= adm_e($s['descriere']) ?></span>
            </button>
        </form>
    <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
