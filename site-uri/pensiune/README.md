# Pachetul `pensiune`

Pachetul de date al unui site de prezentare mutat de pe WordPress (Divi 4) pe drumul capturat (docs/DEPLOY.md, „Adoptarea unui
site WordPress”). Aici stă doar configurația generică a adoptării: cadrul capturat și runtime-ul (`tema/tema.json`), adresele ca
WordPress (`wp-compat.json`), regulile publice ale importului (`import-wordpress.json`: drumul capturat, dialectul, paginile
scoase cu 301 spre prima pagină, pagina de pe care se iau stilurile subsolului Theme Builder), SEO-ul (`seo.json`) și ce se schimbă pentru client (`ce-se-schimba.md`).

Tot ce ține de site-ul real (domeniul, numele, sha256-urile scripturilor scoase, clasele de structură observate, referința
parității, lista neagră a igienei) stă **doar** în `privat/`, ignorat de git și copiat de mână pe server:

| Fișier din `privat/` | Ce ține | Cine îl citește |
|---|---|---|
| `import-wordpress.json` | `scripturi_aruncate` (sha256 cu motivul), `captura.nonce` | `ImportWordPress::reguli()` |
| `captura-structura.json` | `{"<cale>": {"et-last-child": [...]}}` | `ImportWordPress::reguli()` → `captura.structura` |
| `paritate.json` | referința și pragurile harness-ului de paritate | `tools/dev/paritate-wp/` |
| `lista-neagra.txt` | termenii care nu au voie în git (domeniul, numele, telefoanele, adresele) | verificarea 11b din `tools/dev/verifica-rute.php` |
