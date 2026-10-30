<?php

declare(strict_types=1);

/**
 * WP-R din 30 oct 2026 (Bioscem e2e2d77 … 739f58c, newsletterul): ce schimbă paginile newsletterului din admin față de
 * f5ae5ea, pentru comparația istorică (tools/dev/compara-cu-baza.php --baza=f5ae5ea, lista $porturiBioscemNewsletter): lista
 * de newslettere, lista de abonați și builderul unui template nou. Fiecare intrare: „nou” = textul scris acum (cu atâtea rânduri
 * neschimbate în jur cât să fie unic în pagină și să nu apară pe altă pagină; „ori” = de câte ori apare, același pe fiecare rând
 * al unei liste), „vechi” = ce scrie f5ae5ea în locul lui, „din” = commit-ul Bioscem (sau commit-urile) care a făcut schimbarea,
 * „ce” = schimbarea. Scrise din diferența dintre cele două pagini, după celelalte declarații ale comparației istorice. Față de
 * codul Bioscem însuși (G-cmp) nu se aplică: acolo paginile ies octet cu octet. Două valori depind de server și le pune
 * compara-cu-baza.php: adresa site-ului (scrisă aici cu portul 8701) și `?v=<V>` (data fișierului din public/).
 *
 * @return list<array{cale: string, nou: string, vechi: string, ori: int, din: string, ce: string}>
 */
return [
    [
        'cale' => '/admin/emails/newsletters?tab=campaigns',
        'din' => '42984c1',
        'ce' => 'lista de newslettere: antetul ca componentă (antet-pagina, cu ajutorul din spatele lui „?”) și foile componentelor',
        'ori' => 1,
        'nou' => <<<'TXT'
                                <link rel="stylesheet" href="/assets/css/admin-componente.css?v=<V>">
            <script src="/assets/js/admin/componente.js?v=<V>" defer></script>
            <div style="margin-top:14px;">
            <header class="adm-antet" id="nl-antet-newslettere">
    <div class="adm-antet__text">
        <h2 class="adm-antet__titlu">
            Newslettere                            <button type="button" class="adm-ajutor__buton" aria-expanded="false" aria-controls="nl-antet-newslettere-ajutor" title="Ajutor: ce fac aici?">?</button>
                    </h2>
                    <p class="adm-antet__subtitlu">Apasă „+ Newsletter nou”, alege un șablon și urmează cei 4 pași: Conținut → Destinatari → Verificare → Trimite / Programează.</p>
            </div>
            <div class="adm-antet__actiuni"><button type="button" class="btn" id="campaign-new-btn">+ Newsletter nou</button></div>
    </header>
    <div class="adm-ajutor" id="nl-antet-newslettere-ajutor" hidden>
                    <p>Ciornă: încă îl lucrezi. Programat: pleacă singur la ora aleasă. Se trimite: pleacă acum, pe bucăți, automat. Oprit: serverul de email a refuzat mesaje; deschide-l și apasă „Reia trimiterea”. Trimis: gata.</p>
                    <p>Un newsletter care a început să plece nu se mai modifică. Pentru o variantă nouă, folosește butonul ⧉ (Duplică).</p>
                    <p>Nimeni nu primește același newsletter de două ori, iar cine s-a dezabonat nu-l primește deloc.</p>
            </div>

TXT,
        'vechi' => <<<'TXT'
                    <div class="newsletter-template-header-row">
                <h3 class="newsletter-block-title">Newslettere</h3>
                <button type="button" class="btn" id="campaign-new-btn">+ Newsletter nou</button>

TXT,
    ],
    [
        'cale' => '/admin/emails/newsletters?tab=campaigns',
        'din' => 'e2e2d77',
        'ce' => 'formularul campaniei: listele cu numărul abonaților activi',
        'ori' => 1,
        'nou' => <<<'TXT'
                            <select name="campaign_list_id" required>
                                                                    <option value="1">
                                        Abonați site (27 abonați activi)
                                    </option>
                                                                    <option value="2">
                                        Clienți fideli (9 abonați activi)
                                    </option>
                                                            </select>

TXT,
        'vechi' => <<<'TXT'
                            <select name="campaign_list_id" required>
                                                                    <option value="1">
                                        Abonați site (30)
                                    </option>
                                                                    <option value="2">
                                        Clienți fideli (10)
                                    </option>
                                                            </select>

TXT,
    ],
    [
        'cale' => '/admin/emails/newsletters?tab=campaigns',
        'din' => 'e2e2d77',
        'ce' => 'cardul campaniei: „Programat” doar cât e programat, motivul unei campanii oprite (rândurile PHP noi lasă alte spații)',
        'ori' => 1,
        'nou' => <<<'TXT'
                                    <p>Noutăți demo</p>
                                                                                                        </div>
                                <div class="newsletter-campaign-meta">

TXT,
        'vechi' => <<<'TXT'
                                    <p>Noutăți demo</p>
                                                                    </div>
                                <div class="newsletter-campaign-meta">

TXT,
    ],
    [
        'cale' => '/admin/emails/newsletters?tab=campaigns',
        'din' => 'e2e2d77',
        'ce' => 'cardul campaniei: „Programat” doar cât e programat, motivul unei campanii oprite (rândurile PHP noi lasă alte spații)',
        'ori' => 1,
        'nou' => <<<'TXT'
                                    <p>Ciornă demo</p>
                                                                                                        </div>
                                <div class="newsletter-campaign-meta">

TXT,
        'vechi' => <<<'TXT'
                                    <p>Ciornă demo</p>
                                                                    </div>
                                <div class="newsletter-campaign-meta">

TXT,
    ],
    [
        'cale' => '/admin/emails/newsletters?tab=campaigns',
        'din' => 'e2e2d77',
        'ce' => 'un newsletter trimis se deschide, nu se mai editează („Deschide newsletterul”)',
        'ori' => 1,
        'nou' => <<<'TXT'
                                        <a class="icon-btn" href="/admin/emails/builder?type=campaign&id=1" title="Deschide newsletterul">✎</a>

TXT,
        'vechi' => <<<'TXT'
                                        <a class="icon-btn" href="/admin/emails/builder?type=campaign&id=1" title="Editează newsletterul">✎</a>

TXT,
    ],
    [
        'cale' => '/admin/emails/newsletters?tab=subscribers',
        'din' => 'e2e2d77, 7123076',
        'ce' => 'lista de abonați: starea pe înțeles („dezabonat”), confirmarea înaintea reactivării unui dezabonat, butonul ⟲ ascuns la „neconfirmat”',
        'ori' => 3,
        'nou' => <<<'TXT'
                                                                <td><span class="status-pill off" >dezabonat</span></td>
                                <td class="template-actions">
                                                                        <form method="post" action="/admin/emails" style="display:inline;"
                                        onsubmit="return confirm('Această persoană s-a dezabonat. Reactiveaz-o doar dacă ți-a cerut explicit să primească din nou newslettere. Continui?');">

TXT,
        'vechi' => <<<'TXT'
                                <td><span class="status-pill off">unsubscribed</span></td>
                                <td class="template-actions">
                                    <form method="post" action="/admin/emails" style="display:inline;">

TXT,
    ],
    [
        'cale' => '/admin/emails/newsletters?tab=subscribers',
        'din' => '7123076',
        'ce' => 'lista de abonați: nota despre „neconfirmat” (dubla confirmare) și lista „nu trimite”',
        'ori' => 1,
        'nou' => <<<'TXT'
                    <p style="margin:0 0 10px;color:#64748b;font-size:12px;">
                        <strong>neconfirmat</strong> = s-a înscris din formularul de pe site și n-a apăsat încă linkul din emailul
                        de confirmare (nu primește newslettere; cererea expiră după 30 de zile). Un abonat șters intră pe lista
                        „nu trimite”: importurile și adăugarea din admin îl sar; doar el se poate reabona, de pe site.
                    </p>

TXT,
        'vechi' => '',
    ],
    [
        'cale' => '/admin/emails/newsletters?tab=subscribers',
        'din' => 'e2e2d77',
        'ce' => 'lista de abonați: adresele și abonații activi ai fiecărei liste',
        'ori' => 1,
        'nou' => <<<'TXT'
                                <a href="/admin/emails/newsletters?tab=subscribers&list=2">
                                    <strong>Clienți fideli</strong>
                                    <small>10 adrese · 9 abonați activi</small>
                                </a>
                                                                    <form method="post" action="/admin/emails" onsubmit="return confirm('Ștergi lista?');">

TXT,
        'vechi' => <<<'TXT'
                                <a href="/admin/emails/newsletters?tab=subscribers&list=2">
                                    <strong>Clienți fideli</strong>
                                    <small>10 abonați</small>
                                </a>
                                                                    <form method="post" action="/admin/emails" onsubmit="return confirm('Ștergi lista?');">

TXT,
    ],
    [
        'cale' => '/admin/emails/newsletters?tab=subscribers',
        'din' => 'e2e2d77, 7123076',
        'ce' => 'lista de abonați: starea pe înțeles („abonat”), formularul ⟲ fără confirmare la un abonat activ, ascuns la „neconfirmat”',
        'ori' => 27,
        'nou' => <<<'TXT'
                                                                <td><span class="status-pill ok" >abonat</span></td>
                                <td class="template-actions">
                                                                        <form method="post" action="/admin/emails" style="display:inline;"
                                        >

TXT,
        'vechi' => <<<'TXT'
                                <td><span class="status-pill ok">active</span></td>
                                <td class="template-actions">
                                    <form method="post" action="/admin/emails" style="display:inline;">

TXT,
    ],
    [
        'cale' => '/admin/emails/newsletters?tab=subscribers',
        'din' => 'e2e2d77',
        'ce' => 'lista de abonați: adresele și abonații activi ai fiecărei liste',
        'ori' => 1,
        'nou' => <<<'TXT'
                                <a href="/admin/emails/newsletters?tab=subscribers&list=1">
                                    <strong>Abonați site</strong>
                                    <small>30 adrese · 27 abonați activi</small>
                                </a>
                                                            </div>

TXT,
        'vechi' => <<<'TXT'
                                <a href="/admin/emails/newsletters?tab=subscribers&list=1">
                                    <strong>Abonați site</strong>
                                    <small>30 abonați</small>
                                </a>
                                                            </div>

TXT,
    ],
    [
        'cale' => '/admin/emails/newsletters?tab=subscribers',
        'din' => '7123076',
        'ce' => 'lista de abonați: ștergerea pune adresa pe lista „nu trimite” (confirmarea o spune)',
        'ori' => 30,
        'nou' => <<<'TXT'
                                                                        <form method="post" action="/admin/emails" style="display:inline;" onsubmit="return confirm('Ștergi abonatul? Adresa intră pe lista „nu trimite”: nu mai poate fi adăugată din admin sau din import; doar persoana se poate reabona singură, de pe site.');">

TXT,
        'vechi' => <<<'TXT'
                                    <form method="post" action="/admin/emails" style="display:inline;" onsubmit="return confirm('Ștergi abonatul?');">

TXT,
    ],
    [
        'cale' => '/admin/emails/newsletters?tab=subscribers',
        'din' => 'f56a3b5',
        'ce' => 'lista de abonați: nota limitei globale a formularului de abonare (goală aici; rândul PHP nou lasă alte spații)',
        'ori' => 1,
        'nou' => <<<'TXT'
                                <div class="newsletter-subscribers-grid">

TXT,
        'vechi' => <<<'TXT'
                    <div class="newsletter-subscribers-grid">

TXT,
    ],
    [
        'cale' => '/admin/emails/builder',
        'din' => '42984c1',
        'ce' => 'builderul: „Alege un șablon” cu șabloanele magazinului (NewsletterSabloane: logo-ul și culorile din tema pachetului, produsele demo)',
        'ori' => 1,
        'nou' => <<<'TXT'
  // Șabloanele magazinului vin de la server (NewsletterSabloane), cu produse reale.
  const sabloaneMagazin = {"noutati":[{"type":"image","image_url":"/uploads/gallery/bioscem-logo-2.png","alt":"Bioscem","width":40,"align":"center","link_url":"/","background":"#ffffff","padding_top":20,"padding_bottom":16},{"type":"header","content":"Noutățile lunii de la Bioscem","align":"center","background":"#075d61","text_color":"#ffffff","font_size":28,"font_size_mobile":22,"padding_top":26,"padding_bottom":26,"padding_left":20,"padding_right":20},{"type":"text","content":"Bună,\n\nÎți scriem câteva rânduri despre ce e nou la noi luna aceasta. Scrie aici mesajul tău: o veste, un sfat, o recomandare.","align":"left","background":"#ffffff","text_color":"#18353a","font_size":16,"font_size_mobile":15,"padding_top":20,"padding_bottom":12,"padding_left":28,"padding_right":28},{"type":"text","content":"\u003Cb\u003EProduse recomandate\u003C/b\u003E","align":"center","background":"#ffffff","text_color":"#0b3f52","font_size":18,"font_size_mobile":17,"padding_top":18,"padding_bottom":4},{"type":"columns_2","background":"#ffffff","padding_left":12,"padding_right":12,"columns":[[{"type":"image","image_url":"/uploads/demo/cos-cadou-cu-miere.png","alt":"Coș cadou cu miere","width":100,"align":"center","link_url":"/produs/cos-cadou-cu-miere","border_radius":6,"background":"#ffffff"},{"type":"text","content":"Coș cadou cu miere\n\u003Cs\u003E149,00 lei\u003C/s\u003E \u003Cb\u003E129,00 lei\u003C/b\u003E","align":"center","background":"#ffffff","text_color":"#18353a","font_size":15,"font_size_mobile":15,"padding_top":8,"padding_bottom":6},{"type":"button","label":"Vezi produsul","url":"/produs/cos-cadou-cu-miere","align":"center","background":"#ffffff","button_color":"#075d61","text_color":"#ffffff","border_radius":6,"font_size":14,"btn_pad_v":9,"btn_pad_h":16,"padding_bottom":10}],[{"type":"image","image_url":"/uploads/demo/balsam-de-buze-cu-ceara-de-albine.png","alt":"Balsam de buze cu ceară de albine","width":100,"align":"center","link_url":"/produs/balsam-de-buze-cu-ceara-de-albine","border_radius":6,"background":"#ffffff"},{"type":"text","content":"Balsam de buze cu ceară de albine\n\u003Cb\u003E12,00 lei\u003C/b\u003E","align":"center","background":"#ffffff","text_color":"#18353a","font_size":15,"font_size_mobile":15,"padding_top":8,"padding_bottom":6},{"type":"button","label":"Vezi produsul","url":"/produs/balsam-de-buze-cu-ceara-de-albine","align":"center","background":"#ffffff","button_color":"#075d61","text_color":"#ffffff","border_radius":6,"font_size":14,"btn_pad_v":9,"btn_pad_h":16,"padding_bottom":10}]]},{"type":"columns_2","background":"#ffffff","padding_left":12,"padding_right":12,"columns":[[{"type":"image","image_url":"/uploads/demo/ceai-de-menta.png","alt":"Ceai de mentă","width":100,"align":"center","link_url":"/produs/ceai-de-menta","border_radius":6,"background":"#ffffff"},{"type":"text","content":"Ceai de mentă\n\u003Cb\u003E14,50 lei\u003C/b\u003E","align":"center","background":"#ffffff","text_color":"#18353a","font_size":15,"font_size_mobile":15,"padding_top":8,"padding_bottom":6},{"type":"button","label":"Vezi produsul","url":"/produs/ceai-de-menta","align":"center","background":"#ffffff","button_color":"#075d61","text_color":"#ffffff","border_radius":6,"font_size":14,"btn_pad_v":9,"btn_pad_h":16,"padding_bottom":10}],[{"type":"image","image_url":"/uploads/demo/set-cadou-ceaiuri.png","alt":"Set cadou ceaiuri","width":100,"align":"center","link_url":"/produs/set-cadou-ceaiuri","border_radius":6,"background":"#ffffff"},{"type":"text","content":"Set cadou ceaiuri\n\u003Cb\u003E79,00 lei\u003C/b\u003E","align":"center","background":"#ffffff","text_color":"#18353a","font_size":15,"font_size_mobile":15,"padding_top":8,"padding_bottom":6},{"type":"button","label":"Vezi produsul","url":"/produs/set-cadou-ceaiuri","align":"center","background":"#ffffff","button_color":"#075d61","text_color":"#ffffff","border_radius":6,"font_size":14,"btn_pad_v":9,"btn_pad_h":16,"padding_bottom":10}]]},{"type":"divider","background":"#ffffff","color":"#e3ecec","width":90,"thickness":1,"padding_top":8,"padding_bottom":0},{"type":"button","label":"Vezi tot magazinul","url":"/magazin","align":"center","background":"#ffffff","button_color":"#075d61","text_color":"#ffffff","border_radius":6,"font_size":16,"btn_pad_v":13,"btn_pad_h":28,"padding_top":16,"padding_bottom":26},{"type":"spacer","height":12,"background":"#ffffff"},{"type":"text","content":"\u003Cb\u003EBioscem\u003C/b\u003E\nPrimești acest email pentru că te-ai abonat la noutățile noastre pe 127.0.0.1:8701.","align":"center","background":"#eaf6f5","text_color":"#6b7d80","font_size":13,"font_size_mobile":13,"padding_top":18,"padding_bottom":18,"padding_left":24,"padding_right":24}],"oferta":[{"type":"image","image_url":"/uploads/gallery/bioscem-logo-2.png","alt":"Bioscem","width":40,"align":"center","link_url":"/","background":"#ffffff","padding_top":20,"padding_bottom":16},{"type":"header","content":"-20% la produsele selectate","align":"center","background":"#075d61","text_color":"#ffffff","font_size":34,"font_size_mobile":26,"padding_top":26,"padding_bottom":26,"padding_left":20,"padding_right":20},{"type":"text","content":"Doar până duminică: folosește codul de mai jos în coș și primești reducerea pe loc.","align":"center","background":"#ffffff","text_color":"#18353a","font_size":16,"font_size_mobile":15,"padding_top":20,"padding_bottom":12,"padding_left":28,"padding_right":28},{"type":"text","content":"\u003Cb\u003ECOD: TOAMNA20\u003C/b\u003E","align":"center","background":"#eaf6f5","text_color":"#0b3f52","font_size":24,"font_size_mobile":20,"padding_top":14,"padding_bottom":14,"padding_left":24,"padding_right":24},{"type":"text","content":"\u003Cb\u003EÎn ofertă\u003C/b\u003E","align":"center","background":"#ffffff","text_color":"#0b3f52","font_size":18,"font_size_mobile":17,"padding_top":18,"padding_bottom":4},{"type":"columns_2","background":"#ffffff","padding_left":12,"padding_right":12,"columns":[[{"type":"image","image_url":"/uploads/demo/cos-cadou-cu-miere.png","alt":"Coș cadou cu miere","width":100,"align":"center","link_url":"/produs/cos-cadou-cu-miere","border_radius":6,"background":"#ffffff"},{"type":"text","content":"Coș cadou cu miere\n\u003Cs\u003E149,00 lei\u003C/s\u003E \u003Cb\u003E129,00 lei\u003C/b\u003E","align":"center","background":"#ffffff","text_color":"#18353a","font_size":15,"font_size_mobile":15,"padding_top":8,"padding_bottom":6},{"type":"button","label":"Vezi produsul","url":"/produs/cos-cadou-cu-miere","align":"center","background":"#ffffff","button_color":"#075d61","text_color":"#ffffff","border_radius":6,"font_size":14,"btn_pad_v":9,"btn_pad_h":16,"padding_bottom":10}],[{"type":"image","image_url":"/uploads/demo/balsam-de-buze-cu-ceara-de-albine.png","alt":"Balsam de buze cu ceară de albine","width":100,"align":"center","link_url":"/produs/balsam-de-buze-cu-ceara-de-albine","border_radius":6,"background":"#ffffff"},{"type":"text","content":"Balsam de buze cu ceară de albine\n\u003Cb\u003E12,00 lei\u003C/b\u003E","align":"center","background":"#ffffff","text_color":"#18353a","font_size":15,"font_size_mobile":15,"padding_top":8,"padding_bottom":6},{"type":"button","label":"Vezi produsul","url":"/produs/balsam-de-buze-cu-ceara-de-albine","align":"center","background":"#ffffff","button_color":"#075d61","text_color":"#ffffff","border_radius":6,"font_size":14,"btn_pad_v":9,"btn_pad_h":16,"padding_bottom":10}]]},{"type":"divider","background":"#ffffff","color":"#e3ecec","width":90,"thickness":1,"padding_top":8,"padding_bottom":0},{"type":"button","label":"Cumpără acum","url":"/magazin","align":"center","background":"#ffffff","button_color":"#075d61","text_color":"#ffffff","border_radius":6,"font_size":16,"btn_pad_v":13,"btn_pad_h":28,"padding_top":16,"padding_bottom":26},{"type":"spacer","height":12,"background":"#ffffff"},{"type":"text","content":"\u003Cb\u003EBioscem\u003C/b\u003E\nPrimești acest email pentru că te-ai abonat la noutățile noastre pe 127.0.0.1:8701.","align":"center","background":"#eaf6f5","text_color":"#6b7d80","font_size":13,"font_size_mobile":13,"padding_top":18,"padding_bottom":18,"padding_left":24,"padding_right":24}],"sfat":[{"type":"image","image_url":"/uploads/gallery/bioscem-logo-2.png","alt":"Bioscem","width":40,"align":"center","link_url":"/","background":"#ffffff","padding_top":20,"padding_bottom":16},{"type":"header","content":"Un sfat pentru săptămâna aceasta","align":"center","background":"#075d61","text_color":"#ffffff","font_size":28,"font_size_mobile":22,"padding_top":26,"padding_bottom":26,"padding_left":20,"padding_right":20},{"type":"text","content":"Scrie aici sfatul tău, în 3–4 rânduri. Oamenii citesc pe telefon: fraze scurte, un singur mesaj.","align":"left","background":"#ffffff","text_color":"#18353a","font_size":16,"font_size_mobile":15,"padding_top":20,"padding_bottom":12,"padding_left":28,"padding_right":28},{"type":"quote","content":"Un citat scurt sau părerea unui client.","author":"Numele clientului","align":"left","background":"#eaf6f5","text_color":"#0b3f52","border_color":"#075d61","font_size":16,"padding_top":14,"padding_bottom":14,"padding_left":20,"padding_right":24},{"type":"button","label":"Citește tot articolul","url":"/blog","align":"center","background":"#ffffff","button_color":"#075d61","text_color":"#ffffff","border_radius":6,"font_size":16,"btn_pad_v":13,"btn_pad_h":28,"padding_top":16,"padding_bottom":26},{"type":"spacer","height":12,"background":"#ffffff"},{"type":"text","content":"\u003Cb\u003EBioscem\u003C/b\u003E\nPrimești acest email pentru că te-ai abonat la noutățile noastre pe 127.0.0.1:8701.","align":"center","background":"#eaf6f5","text_color":"#6b7d80","font_size":13,"font_size_mobile":13,"padding_top":18,"padding_bottom":18,"padding_left":24,"padding_right":24}],"anunt":[{"type":"image","image_url":"/uploads/gallery/bioscem-logo-2.png","alt":"Bioscem","width":40,"align":"center","link_url":"/","background":"#ffffff","padding_top":20,"padding_bottom":16},{"type":"header","content":"Titlul anunțului","align":"center","background":"#075d61","text_color":"#ffffff","font_size":28,"font_size_mobile":22,"padding_top":26,"padding_bottom":26,"padding_left":20,"padding_right":20},{"type":"text","content":"Scrie aici anunțul (program de sărbători, produs nou, livrare gratuită).","align":"center","background":"#ffffff","text_color":"#18353a","font_size":16,"font_size_mobile":15,"padding_top":20,"padding_bottom":12,"padding_left":28,"padding_right":28},{"type":"button","label":"Află mai multe","url":"/","align":"center","background":"#ffffff","button_color":"#075d61","text_color":"#ffffff","border_radius":6,"font_size":16,"btn_pad_v":13,"btn_pad_h":28,"padding_top":16,"padding_bottom":26},{"type":"spacer","height":12,"background":"#ffffff"},{"type":"text","content":"\u003Cb\u003EBioscem\u003C/b\u003E\nPrimești acest email pentru că te-ai abonat la noutățile noastre pe 127.0.0.1:8701.","align":"center","background":"#eaf6f5","text_color":"#6b7d80","font_size":13,"font_size_mobile":13,"padding_top":18,"padding_bottom":18,"padding_left":24,"padding_right":24}]};
  const tpl = preset.startsWith('magazin:') ? sabloaneMagazin[preset.slice(8)] : starters[preset];
  if(!tpl) return;
  // Un șablon înlocuiește tot conținutul: întrebăm dacă e deja ceva scris (se poate și reveni cu Undo).
  if(blocks.length > 0 && dirty && !confirm('Șablonul înlocuiește conținutul de acum. Continui? (Poți reveni cu „Undo”.)')) return;

TXT,
        'vechi' => <<<'TXT'
  const tpl = starters[preset];
  if(!tpl) return;

TXT,
    ],
    [
        'cale' => '/admin/emails/builder',
        'din' => '42984c1',
        'ce' => 'builderul: înălțimea builderului cu bara de pași',
        'ori' => 1,
        'nou' => <<<'TXT'
  const el = document.getElementById('eb-wrap');
  if (!el) return;
  // Note: we deliberately do NOT lock document/body overflow here — doing so froze
  // the admin sidebar menu. Scroll-chaining from the inner panels to the page is
  // prevented with `overscroll-behavior:contain` on the panels themselves (CSS),
  // so the palette/canvas/props scroll internally without dragging the page.
  const fit = () => {
    el.style.height = (window.innerHeight - (el.getBoundingClientRect().top)) + 'px';
  };
  fit();
  window.addEventListener('resize', fit);
})();

render();
sync();
setTimeout(() => ebShowStarterModal(), 300);

})(); // end IIFE
</script>
<script src="/assets/js/admin/componente.js?v=<V>" defer></script>
            </main>
        </div>
        <script>
            (() => {
                const groups = document.querySelectorAll('.toggle-group');
                const syncCaret = (group) => {
                    const caret = group.querySelector('.caret');
                    if (!caret) return;
                    caret.textContent = group.classList.contains('open') ? '↑' : '↓';
                };
                groups.forEach((group) => {
                    const key = 'admin_toggle_' + group.dataset.toggleGroup;
                    const button = group.querySelector('.group-toggle');
                    const saved = window.localStorage.getItem(key);
                    if (saved === 'open') group.classList.add('open');
                    if (saved === 'closed') group.classList.remove('open');
                    syncCaret(group);
                    button.addEventListener('click', () => {
                        group.classList.toggle('open');

TXT,
        'vechi' => <<<'TXT'
  const el = document.getElementById('eb-wrap');
  if (!el) return;
  // Note: we deliberately do NOT lock document/body overflow here — doing so froze
  // the admin sidebar menu. Scroll-chaining from the inner panels to the page is
  // prevented with `overscroll-behavior:contain` on the panels themselves (CSS),
  // so the palette/canvas/props scroll internally without dragging the page.
  const fit = () => {
    el.style.height = (window.innerHeight - (el.getBoundingClientRect().top)) + 'px';
  };
  fit();
  window.addEventListener('resize', fit);
})();

render();
sync();
setTimeout(() => ebShowStarterModal(), 300);

})(); // end IIFE
</script>
            </main>
        </div>
        <script>
            (() => {
                const groups = document.querySelectorAll('.toggle-group');
                const syncCaret = (group) => {
                    const caret = group.querySelector('.caret');
                    if (!caret) return;
                    caret.textContent = group.classList.contains('open') ? '↑' : '↓';
                };
                groups.forEach((group) => {
                    const key = 'admin_toggle_' + group.dataset.toggleGroup;
                    const button = group.querySelector('.group-toggle');
                    const saved = window.localStorage.getItem(key);
                    if (saved === 'open') group.classList.add('open');
                    if (saved === 'closed') group.classList.remove('open');
                    syncCaret(group);
                    button.addEventListener('click', () => {
                        group.classList.toggle('open');

TXT,
    ],
    [
        'cale' => '/admin/emails/builder',
        'din' => '42984c1',
        'ce' => 'builderul: grupul șabloanelor magazinului în „Alege un șablon”',
        'ori' => 1,
        'nou' => <<<'TXT'
            <p class="nlp-sectiune-titlu">Cu logo-ul și culorile magazinului</p>
      <div class="nlp-sabloane">
                <button type="button" class="nlp-sablon" onclick="ebApplyStarter('magazin:noutati')">
          <span class="nlp-sablon__mostra" aria-hidden="true"><i></i><i></i><i></i></span>
          <strong>Noutăți și produse</strong>
          <span>Logo, un mesaj scurt, 4 produse și buton spre magazin.</span>
        </button>
                <button type="button" class="nlp-sablon" onclick="ebApplyStarter('magazin:oferta')">
          <span class="nlp-sablon__mostra" aria-hidden="true"><i></i><i></i><i></i></span>
          <strong>Ofertă / reducere</strong>
          <span>Titlu mare cu reducerea, codul de cupon, produse în ofertă.</span>
        </button>
                <button type="button" class="nlp-sablon" onclick="ebApplyStarter('magazin:sfat')">
          <span class="nlp-sablon__mostra" aria-hidden="true"><i></i><i></i><i></i></span>
          <strong>Sfat / articol</strong>
          <span>Un sfat util, un citat și link spre blog sau spre un produs.</span>
        </button>
                <button type="button" class="nlp-sablon" onclick="ebApplyStarter('magazin:anunt')">
          <span class="nlp-sablon__mostra" aria-hidden="true"><i></i><i></i><i></i></span>
          <strong>Anunț scurt</strong>
          <span>Logo, titlu, două rânduri și un buton. Pentru vești rapide.</span>
        </button>
              </div>
      <p class="nlp-sectiune-titlu">Simple</p>
            <div class="eb-starter-grid">

TXT,
        'vechi' => <<<'TXT'
      <div class="eb-starter-grid">

TXT,
    ],
    [
        'cale' => '/admin/emails/builder',
        'din' => '42984c1',
        'ce' => 'builderul: pașii newsletterului (Destinatari, Verificare, Trimite) în locul vrăjitorului vechi; window.ebBuilder',
        'ori' => 1,
        'nou' => <<<'TXT'
   PAȘII NEWSLETTERULUI (Destinatari, Verificare, Trimite)
   Logica e în /assets/js/admin/newsletter-pasi.js; aici doar ce
   ține de starea builderului (blocurile, salvarea, „modificat”).
═══════════════════════════════════════════════════════════ */
window.ebBuilder = {
  blocuri: () => JSON.parse(JSON.stringify(blocks)),
  sincronizeaza: () => sync(),
  esteModificat: () => dirty,
  marcheazaSalvat: () => clearDirty(),
  marcheazaModificat: () => markDirty(),
};

TXT,
        'vechi' => <<<'TXT'
   CAMPAIGN WIZARD
═══════════════════════════════════════════════════════════ */

TXT,
    ],
    [
        'cale' => '/admin/emails/builder',
        'din' => '42984c1',
        'ce' => 'builderul: previzualizarea calculator / telefon',
        'ori' => 1,
        'nou' => <<<'TXT'
        <button data-preview="desktop">🖥 Desktop</button>
        <button data-preview="mobile">📱 Mobil</button>
      </div>
      <button type="button" id="btn-save" class="btn" style="padding:6px 16px;">Salvează</button>
          </div>
  </div>

  <!-- ── Hidden save form ── -->
  <form id="eb-form" method="post" action="/admin/emails/builder" style="display:none;">

TXT,
        'vechi' => <<<'TXT'
        <button data-preview="desktop">🖥 Desktop</button>
        <button data-preview="mobile">📱 Mobil</button>
      </div>
      <button type="button" id="btn-save" class="btn" style="padding:6px 16px;">Salvează</button>
    </div>
  </div>

  <!-- ── Hidden save form ── -->
  <form id="eb-form" method="post" action="/admin/emails/builder" style="display:none;">

TXT,
    ],
    [
        'cale' => '/admin/emails/builder',
        'din' => '42984c1',
        'ce' => 'builderul: „Alege un șablon” în locul „Template de start”',
        'ori' => 1,
        'nou' => <<<'TXT'
                  <div class="eb-palette-section">
        <button type="button" class="btn btn-secondary" style="width:100%;font-size:12px;" onclick="ebShowStarterModal()">✦ Alege un șablon</button>
      </div>

TXT,
        'vechi' => <<<'TXT'
                  <div class="eb-palette-section">
        <button type="button" class="btn btn-secondary" style="width:100%;font-size:12px;" onclick="ebShowStarterModal()">✦ Template de start</button>
      </div>

TXT,
    ],
    [
        'cale' => '/admin/emails/builder',
        'din' => '42984c1',
        'ce' => 'builderul: foile componentelor și ale pașilor newsletterului',
        'ori' => 1,
        'nou' => <<<'TXT'
                                                <link rel="stylesheet" href="/assets/css/admin-componente.css?v=<V>">
<link rel="stylesheet" href="/assets/css/admin-newsletter.css?v=<V>">
<style>

TXT,
        'vechi' => <<<'TXT'
                                                <style>

TXT,
    ],
    [
        'cale' => '/admin/emails/builder',
        'din' => '42984c1',
        'ce' => 'builderul: „Alege un șablon” în locul „Template de start”',
        'ori' => 1,
        'nou' => <<<'TXT'
    <div class="eb-modal-header">
      <h3>Alege un șablon</h3>
      <button type="button" class="eb-modal-close" onclick="ebCloseStarterModal()">✕</button>

TXT,
        'vechi' => <<<'TXT'
    <div class="eb-modal-header">
      <h3>Alege un template de start</h3>
      <button type="button" class="eb-modal-close" onclick="ebCloseStarterModal()">✕</button>

TXT,
    ],
    [
        'cale' => '/admin/emails/builder',
        'din' => '42984c1',
        'ce' => 'builderul: stilurile vrăjitorului vechi scoase (pașii au foaia lor, admin-newsletter.css)',
        'ori' => 1,
        'nou' => <<<'TXT'
.eb-modal-url-input{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;margin-top:8px;box-sizing:border-box;}

</style>


TXT,
        'vechi' => <<<'TXT'
.eb-modal-url-input{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;margin-top:8px;box-sizing:border-box;}

/* ── Campaign Wizard ─────────────────────────────────────── */
.eb-wizard-bar{display:flex;align-items:center;gap:0;background:#fff;border-bottom:1px solid #e5e7eb;flex-shrink:0;padding:0 14px;overflow:hidden;}
.eb-wizard-step{display:flex;align-items:center;gap:7px;padding:10px 12px;font-size:13px;font-weight:500;color:#6b7280;cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap;flex-shrink:0;transition:color .15s,border-color .15s;background:none;border-left:none;border-right:none;border-top:none;}
.eb-wizard-step:hover{color:#111827;}
.eb-wizard-step.active{color:#1e6b50;border-bottom-color:#1e6b50;}
.eb-wizard-step-num{width:20px;height:20px;border-radius:50%;background:#e5e7eb;color:#374151;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.eb-wizard-step.active .eb-wizard-step-num{background:#1e6b50;color:#fff;}
.eb-wizard-step.done .eb-wizard-step-num{background:#d1fae5;color:#065f46;}
.eb-wizard-step-sep{color:#d1d5db;font-size:12px;padding:0 4px;pointer-events:none;}
.eb-wizard-panel{flex:1;overflow-y:auto;padding:28px 32px;background:#f9fafb;}
.eb-wiz-section{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:24px;max-width:640px;margin:0 auto;}
.eb-wiz-section h3{margin:0 0 16px;font-size:15px;color:#111827;}
.eb-wiz-field{margin-bottom:16px;}
.eb-wiz-field label{display:block;font-size:12.5px;font-weight:600;color:#374151;margin-bottom:5px;}
.eb-wiz-field input,.eb-wiz-field select,.eb-wiz-field textarea{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;color:#111827;background:#fff;box-sizing:border-box;}
.eb-wiz-field input:focus,.eb-wiz-field select:focus,.eb-wiz-field textarea:focus{outline:none;border-color:#1e6b50;box-shadow:0 0 0 2px rgba(30,107,80,.1);}
.eb-wiz-field small{display:block;font-size:11px;color:#9ca3af;margin-top:3px;}
.eb-wiz-actions{display:flex;gap:10px;align-items:center;justify-content:flex-end;margin-top:20px;flex-wrap:wrap;}
.eb-wiz-actions .btn{padding:8px 20px;}
.eb-wiz-list-card{border:2px solid #e5e7eb;border-radius:8px;padding:14px 16px;cursor:pointer;margin-bottom:8px;display:flex;align-items:center;gap:12px;transition:border-color .15s,background .15s;}
.eb-wiz-list-card:hover{border-color:#a7f3d0;background:#f0fdf4;}
.eb-wiz-list-card.selected{border-color:#1e6b50;background:#f0fdf4;}
.eb-wiz-list-card .eb-wiz-list-name{font-weight:600;font-size:13.5px;color:#111827;}
.eb-wiz-list-card .eb-wiz-list-count{font-size:12px;color:#6b7280;}
.eb-wiz-list-card .eb-wiz-list-check{margin-left:auto;font-size:18px;color:#1e6b50;display:none;}
.eb-wiz-list-card.selected .eb-wiz-list-check{display:block;}
.eb-wiz-preview-wrap{display:flex;flex-direction:column;gap:16px;max-width:660px;margin:0 auto;}
.eb-wiz-preview-frame{background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;}
.eb-wiz-preview-tabs{display:flex;gap:8px;justify-content:center;}
.eb-wiz-preview-tabs button{padding:6px 18px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;background:#fff;cursor:pointer;color:#6b7280;}
.eb-wiz-preview-tabs button.active{background:#1e6b50;color:#fff;border-color:#1e6b50;}
.eb-wiz-send-box{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;}
.eb-wiz-send-box h4{margin:0 0 14px;font-size:14px;color:#111827;}
.eb-wiz-send-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px;}
.eb-wiz-send-row:last-child{margin-bottom:0;}
.eb-wiz-send-row input[type=email],.eb-wiz-send-row input[type=datetime-local]{padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;flex:1;min-width:0;}
.status-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:12px;font-size:11.5px;font-weight:600;}
.status-badge.draft{background:#f3f4f6;color:#374151;}
.status-badge.scheduled{background:#fef9c3;color:#854d0e;}
.status-badge.sent{background:#d1fae5;color:#065f46;}
</style>


TXT,
    ],
    [
        'cale' => '/admin/emails/builder',
        'din' => '42984c1',
        'ce' => 'builderul: paleta (subiectul și numele intern sus, spațiile șablonului nou)',
        'ori' => 1,
        'nou' => <<<'TXT'
    <aside class="eb-palette">
            <div class="eb-palette-section">
        <div class="eb-palette-title">Blocuri</div>

TXT,
        'vechi' => <<<'TXT'
    <aside class="eb-palette">
      <div class="eb-palette-section">
        <div class="eb-palette-title">Blocuri</div>

TXT,
    ],
];
