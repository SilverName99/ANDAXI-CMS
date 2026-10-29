<?php
/**
 * Regulile CSS ale cardului de produs (WP-19: C14e), fără eticheta <style>: le pune în stilurile lui catalogul
 * (shop-catalog.php, în același loc ca înainte) și secțiunea {{products_by_template:…}} (products-by-template.php),
 * ca ambele să aibă exact aceleași reguli.
 */
?>
/* exact popular cards */
.bv-popular-card{
  background:#fff;border:1px solid #dbe7df;border-radius:22px;overflow:hidden;box-shadow:0 1px 0 rgba(0,0,0,.02);
  transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease;
}
.bv-popular-card:hover{transform:translateY(-2px);border-color:#cfe0d7;box-shadow:0 10px 24px rgba(22,62,45,.08);}
.bv-popular-card__media-wrap{margin:0;padding:14px;background:#edf4ef;border-radius:22px 22px 0 0;}
.bv-popular-card__media{position:relative;display:block;aspect-ratio:1/1;border-radius:14px;overflow:hidden;background:#f5f1eb;}
.bv-popular-card__img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .35s ease, filter .35s ease;}
.bv-popular-card:hover .bv-popular-card__img{transform:scale(1.045);filter:saturate(1.03);}
.bv-popular-card__bubble{position:absolute;top:8px;right:8px;z-index:2;min-width:56px;height:56px;padding:0 8px;border-radius:999px;background:#e31c23;color:#fff;font:800 18px/1 "DM Sans",Arial,sans-serif;letter-spacing:-0.02em;white-space:nowrap;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 8px 16px rgba(227,28,35,.28);}
.bv-popular-card__bubble--value{width:auto;padding:0 14px;font-size:15px;}
.bv-popular-card__bubble--offer{
  left:8px;
  right:auto;
  min-width:0;
  height:auto;
  padding:8px 10px;
  border-radius:999px;
  background:#107a4d;
  color:#fff;
  font:800 11px/1 "DM Sans",Arial,sans-serif;
  letter-spacing:.04em;
  box-shadow:0 8px 18px rgba(16,122,77,.25);
}
.bv-popular-card__body{padding:12px 14px 14px;}
.bv-popular-card__desc{margin:0 0 8px;font-family:"DM Sans",Arial,sans-serif;font-size:12px;font-weight:400;line-height:1.35;letter-spacing:.06em;text-transform:uppercase;color:#6f8277;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.bv-popular-card__name{margin:0 0 8px;font-family:"Playfair Display",Georgia,serif;font-size:26px;font-weight:600;line-height:1.08;color:#1f2a25;}
.bv-popular-card__name a{color:inherit;text-decoration:none;}
.bv-popular-card__rating{margin:0 0 12px;display:flex;align-items:center;gap:6px;}
.bv-popular-card__stars{display:inline-flex;align-items:center;gap:2px;color:#f4b425;}
.bv-popular-card__star{width:14px;height:14px;display:block;}
.bv-popular-card__reviews{font:400 12px/1 "DM Sans",Arial,sans-serif;color:#6f8277;}
.bv-popular-card__bottom{display:flex;align-items:center;justify-content:space-between;gap:10px;}
.bv-popular-card__price{margin:0;font:700 22px/1.1 "DM Sans",Arial,sans-serif;color:#1f2a25;}
.bv-popular-card__old{margin-left:6px;font:500 12px/1 "DM Sans",Arial,sans-serif;color:#c0392b;text-decoration:line-through;}
.bv-popular-card__cart-btn{width:40px;height:40px;border:0;border-radius:999px;background:#1f8b57;color:#fff;display:grid;place-items:center;cursor:pointer;}
.bv-popular-card__cart-btn svg{width:16px;height:16px;}
.bv-popular-card__stock-out{
  display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 12px;border-radius:999px;
  border:1px solid #fecaca;background:#fff5f5;color:#b91c1c;font:700 12px/1 "DM Sans",Arial,sans-serif;white-space:nowrap;
}
