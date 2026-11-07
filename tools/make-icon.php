<?php
/* Fabrique l'icône du plugin.
 *
 *   php tools/make-icon.php
 *
 * Dessinée ici plutôt que déposée en binaire opaque : on peut la relire et la
 * refaire. Le dessin est fait en 1024 puis réduit en 256, pour des bords
 * lissés que GD ne produit pas sur un remplissage direct.
 *
 * Un robinet, une goutte qui en tombe, et un signe d'alerte : une coupure ou
 * une eau colorée annoncée. Un dessin générique, pas le logo du distributeur.
 */

const TAILLE = 256;
const ECHELLE = 4;

$grand = imagecreatetruecolor(TAILLE * ECHELLE, TAILLE * ECHELLE);
imagesavealpha($grand, true);
imagealphablending($grand, false);
imagefill($grand, 0, 0, imagecolorallocatealpha($grand, 0, 0, 0, 127));
imagealphablending($grand, true);

$bleu   = imagecolorallocate($grand, 0x2B, 0x8C, 0xD8);
$ciel   = imagecolorallocate($grand, 0x8C, 0xC8, 0xF0);
$gris   = imagecolorallocate($grand, 0x36, 0x44, 0x4B);
$orange = imagecolorallocate($grand, 0xF2, 0x7A, 0x1A);
$blanc  = imagecolorallocate($grand, 0xFF, 0xFF, 0xFF);

$e = function ($_valeur) { return (int) round($_valeur * ECHELLE); };

/* Le robinet : une poignée, un corps, un bec qui descend. */
imagefilledrectangle($grand, $e(54), $e(24), $e(122), $e(38), $gris);
imagefilledrectangle($grand, $e(80), $e(36), $e(96), $e(58), $gris);
imagefilledrectangle($grand, $e(14), $e(56), $e(150), $e(88), $gris);
imagefilledrectangle($grand, $e(120), $e(56), $e(150), $e(116), $gris);
imagefilledellipse($grand, $e(120), $e(88), $e(60), $e(64), $gris);

/* La goutte : un disque et une pointe vers le bec. */
imagefilledellipse($grand, $e(135), $e(196), $e(84), $e(84), $bleu);
imagefilledpolygon($grand, array_map($e, array(98, 180, 135, 124, 172, 180)), $bleu);
imagefilledellipse($grand, $e(118), $e(200), $e(18), $e(30), $ciel);

/* L'alerte : un disque orange marqué d'un point d'exclamation. */
imagefilledellipse($grand, $e(206), $e(76), $e(84), $e(84), $blanc);
imagefilledellipse($grand, $e(206), $e(76), $e(72), $e(72), $orange);
imagefilledrectangle($grand, $e(200), $e(50), $e(212), $e(86), $blanc);
imagefilledellipse($grand, $e(206), $e(100), $e(13), $e(13), $blanc);

$icone = imagecreatetruecolor(TAILLE, TAILLE);
imagesavealpha($icone, true);
imagealphablending($icone, false);
imagefill($icone, 0, 0, imagecolorallocatealpha($icone, 0, 0, 0, 127));
imagecopyresampled($icone, $grand, 0, 0, 0, 0, TAILLE, TAILLE, TAILLE * ECHELLE, TAILLE * ECHELLE);

$cible = __DIR__ . '/../plugin_info/swdebe_icon.png';
imagepng($icone, $cible);
echo "Écrit : $cible\n";
