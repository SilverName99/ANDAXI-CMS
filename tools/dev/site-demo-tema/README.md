# Pachetul inventat al testului temei

Pachetul de date al unui site inventat, doar cu o temă (`tema/`), în formatul din
[docs/PACHETE.md](../../../docs/PACHETE.md). Îl folosește doar testul temei,
`tools/dev/php.sh tools/dev/test-pluginuri.php --doar-tema` (secțiunea T: efectul în HTML și,
în T4, stilurile calculate, prin `tools/dev/capturi-publice.php verificari`), plus autotestul lui
`tools/dev/verifica-pachet.php`: fiecare cheie din `tema/tema.json` are altă valoare decât cea
implicită, ca efectul ei să se vadă în pagină.

Pachetul demo neutru (`tools/dev/site-demo/`) rămâne fără temă, ca testele lui să vadă
nucleul neschimbat. Nimic de aici nu e al unui client: culorile, logo-ul și textele sunt
inventate.
