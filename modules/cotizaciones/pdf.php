<?php
require_once __DIR__.'/../../core/helpers.php';
require_once __DIR__.'/../../core/Database.php';
session_init(); require_login();
$id=(int)($_GET['id']??0);
if(!$id){header('Location: '.APP_URL.'/modules/cotizaciones/index.php');exit;}
$pdo=Database::get();
$stmt=$pdo->prepare("SELECT cot.*,COALESCE(cl.razon_social,cot.empresa_nombre) AS nombre_empresa,COALESCE(cl.ruc,cot.empresa_ruc) AS ruc_empresa,COALESCE(cl.direccion,cot.empresa_direccion) AS dir_empresa,COALESCE(cl.departamento,cot.empresa_dep) AS dep_empresa,COALESCE(cl.provincia,cot.empresa_prov) AS prov_empresa,COALESCE(cl.distrito,cot.empresa_dist) AS dist_empresa,ej.nombre AS ej_nombre,ej.apellido AS ej_apellido,ej.email AS ej_email,ej.telefono AS ej_telefono,ej.foto AS ej_foto FROM cotizaciones cot LEFT JOIN clientes cl ON cl.id=cot.cliente_id LEFT JOIN usuarios ej ON ej.id=cot.ejecutivo_id WHERE cot.id=?");
$stmt->execute([$id]);$cot=$stmt->fetch();
if(!$cot){header('Location: '.APP_URL.'/modules/cotizaciones/index.php');exit;}
$nq=$pdo->prepare("SELECT cn.precio,n.codigo,n.nombre FROM cotizacion_normas cn JOIN normas_iso n ON n.id=cn.norma_iso_id WHERE cn.cotizacion_id=? ORDER BY n.codigo");
$nq->execute([$id]);$normas=$nq->fetchAll();
$subtotal=(float)array_sum(array_column($normas,'precio'));
$descuento=(float)($cot['descuento']??0);
$base=max(0,$subtotal-$descuento);
$incluye_igv=!empty($cot['incluye_igv']);
$igv=$incluye_igv ? round($base*0.18,2) : 0.0;
$total=round($base+$igv,2);
$ap=$cot['auditoria_precio']??null;
$rep=trim(($cot['contacto_nombre']??'').' '.($cot['contacto_apellido']??''));
if(!$rep)$rep='Representante Legal';
// Dirección desde BD — solo añade dist/prov/dep si no están ya en la dirección
$_da=trim($cot['dir_empresa']??'');
$_loc=array_unique(array_filter([$cot['dist_empresa'],$cot['prov_empresa'],$cot['dep_empresa']]));
$_extra=[];foreach($_loc as $_p){if($_p&&stripos($_da,$_p)===false)$_extra[]=$_p;}
$dir_full=implode(' - ',array_filter(array_merge([$_da],$_extra)))?:'—';
$asesor=$cot['ej_nombre']?trim($cot['ej_nombre'].' '.$cot['ej_apellido']):'Andrés Salazar';
$asesor_em=$cot['ej_email']??'andres.salazar@groupglobaliso.com';
$asesor_tel=$cot['ej_telefono']??'961 399 268';
// Formatear teléfono: solo dígitos agrupados de a 3 (ej: 987654321 → 987 654 321)
$asesor_tel=implode(' ',str_split(preg_replace('/\D/','',(string)$asesor_tel),3));
// Foto dinámica del ejecutivo: busca archivo en assets/usuarios/fotos/{id}.{ext}
$_ej_foto_b64=$IMG_ANDRES; // fallback
if(!empty($cot['ej_foto'])){
  $_foto_path=__DIR__.'/../../assets/usuarios/fotos/'.basename($cot['ej_foto']);
  if(file_exists($_foto_path)){
    $_foto_mime=mime_content_type($_foto_path);
    $_ej_foto_b64='data:'.$_foto_mime.';base64,'.base64_encode(file_get_contents($_foto_path));
  }
}
$fecha=date('d/m/Y',strtotime($cot['fecha_emision']));
$num=$cot['numero'];
$_mes_larg=['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$_ts_em=strtotime($cot['fecha_emision']);
$fecha_larga=date('d',$_ts_em).' de '.$_mes_larg[date('n',$_ts_em)-1].', '.date('Y',$_ts_em);
$_ts_vence=!empty($cot['fecha_vencimiento'])?strtotime($cot['fecha_vencimiento']):strtotime('+3 days',$_ts_em);
$fecha_vence=date('d',$_ts_vence).' de '.$_mes_larg[date('n',$_ts_vence)-1].', '.date('Y',$_ts_vence);
$dias_validez=max(1,(int)round(($_ts_vence-$_ts_em)/86400));
$dias_validez_txt=$dias_validez.' '.($dias_validez===1?'día':'días');
$ecfg=['borrador'=>'Borrador','enviada'=>'Enviada','aceptada'=>'Aceptada','rechazada'=>'Rechazada','vencida'=>'Vencida','desistida'=>'Desistida'];
$el=$ecfg[$cot['estado']]??$cot['estado'];
$obs=$cot['observaciones']?:($cot['notas']??'');
$_campana_cfg=$pdo->query("SELECT valor FROM configuracion WHERE clave='campana_descuento' LIMIT 1")->fetchColumn();
$campana=trim((string)$_campana_cfg)?:('Impulso Empresarial '.date('Y'));
// Auto-regenerar img_constants.php si faltan los ISOs
$_cf  = __DIR__.'/../../storage/assets/pdf/img_constants.php';
$_pd  = __DIR__.'/../../storage/assets/pdf/';
$_ad  = __DIR__.'/../../storage/assets/';
$_regen = !file_exists($_cf) || strpos(file_get_contents($_cf),'IMG_ISO9001')===false || strpos(file_get_contents($_cf),'IMG_FIRMA_GGI')===false;
if ($_regen) {
    $_map = [
        'LOGO_GGI'=>$_ad.'logo-ggi.png','LOGO_COTI'=>$_pd.'logo-coti.png',
        'FOTO_CORP'=>$_pd.'foto-corporativa.png','ANDRES'=>$_pd.'andres.jpeg',
        'QRO'=>$_pd.'qro.png','HAWKEYE'=>$_pd.'hawkeye.png','INGRAIN'=>$_pd.'ingrain.png',
        'QFS'=>$_pd.'qfs.png','SQC'=>$_pd.'sqc.png','UKS'=>$_pd.'uks.png',
        'IAF'=>$_pd.'iaf.png','OECE'=>$_pd.'oece.png','UAF'=>$_pd.'uaf.png',
        'IAS'=>$_pd.'ias.png','EUROPEAN'=>$_pd.'european.png','EGAC'=>$_pd.'egac.png',
        'INTERBANK'=>$_pd.'interbank.png','BCP'=>$_pd.'bcp.png','BANCONACION'=>$_pd.'banconacion.png',
        'ISO9001'=>$_pd.'iso9001.png','ISO14001'=>$_pd.'iso14001.png',
        'ISO45001'=>$_pd.'iso45001.png','ISO37001'=>$_pd.'iso37001.png','ISO50001'=>$_pd.'iso50001.png',
        'FIRMA_GGI'=>$_pd.'firma-sello-jmmr.png',
    ];
    $_out = ["<?php\n"];
    foreach ($_map as $_k=>$_p) {
        if (!file_exists($_p)) continue;
        $_ext = strtolower(pathinfo($_p,PATHINFO_EXTENSION));
        $_mime = in_array($_ext,['jpg','jpeg'])?'image/jpeg':'image/png';
        $_out[] = "\$IMG_{$_k}=\"data:{$_mime};base64,".base64_encode(file_get_contents($_p))."\";\n";
    }
    $_out[] = "?>\n";
    file_put_contents($_cf, implode('',$_out));
}
require_once $_cf;
$IMG_FIRMA_GGI = $IMG_FIRMA_GGI ?? null;
// ISOs — imágenes reales del diseño Canva
$isos_img = [
    'iso9001'  => $IMG_ISO9001  ?? null,
    'iso14001' => $IMG_ISO14001 ?? null,
    'iso45001' => $IMG_ISO45001 ?? null,
    'iso37001' => $IMG_ISO37001 ?? null,
    'iso50001' => $IMG_ISO50001 ?? null,
];
// Cuando se pide descarga (?dl=1) o vista inline (?inline=1): convertir a PDF con Dompdf.
// inline=1 → se sirve para visualizar (lightbox/iframe); dl=1 → descarga (attachment).
$_inline_pdf  = !empty($_GET['inline']);
$_generar_pdf = !empty($_GET['dl']) || $_inline_pdf;
$_pdf_disp    = $_inline_pdf ? 'inline' : 'attachment';
if ($_generar_pdf) ob_start();
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Propuesta Comercial <?=sanitize($num)?></title>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
html,body{font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;background:#b0b8c4;-webkit-print-color-adjust:exact;print-color-adjust:exact;color:#1e293b}
.ab{max-width:800px;margin:12px auto;background:#fff;border-radius:8px;padding:9px 16px;display:flex;gap:10px;align-items:center;box-shadow:0 2px 8px rgba(0,0,0,.1)}
.bp{padding:7px 18px;background:#1A3A8F;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:5px}
.bb{padding:7px 12px;background:#fff;color:#555;border:1.5px solid #dde;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none}
/* A4 exacto 794×1123px */
.pg{width:794px;height:1123px;margin:16px auto;background:#fff;box-shadow:0 6px 32px rgba(0,0,0,.22);position:relative;overflow:hidden;display:block}
/* ── Elementos comunes páginas 2-7 ──
   Medidas calculadas del modelo Canva:
   Escala: imagen 1654px → A4 794px = 0.480x
─────────────────────────────────────── */
/* Barra azul top 28px + acento verde inferior */
.gb{position:absolute;top:0;left:0;right:0;height:28px;background:#1A3A8F;border-bottom:3px solid #2DB140}
/* Logo GGI: top=74, left=33, width=141, height=87px */
.plogo{position:absolute;top:74px;left:33px;width:141px;height:87px;object-fit:contain}
/* Línea verde horizontal: top=55, right=36, width=340px */
.pline{position:absolute;top:55px;right:36px;width:340px;height:3px;background:#2DB140}
/* Footer azul oscuro: height=82px */
.pftr{position:absolute;bottom:0;left:0;right:0;height:82px;background:#1A3A8F;display:flex;align-items:center;justify-content:space-between;padding:0 36px}
.pftr-r{color:#fff;font-size:13px;font-weight:700;text-align:right;line-height:1.5}
.soc{display:flex;gap:6px}
.si{width:21px;height:21px;border:1.5px solid rgba(255,255,255,.5);border-radius:4px;display:flex;align-items:center;justify-content:center}
/* ── Títulos grandes (font=70px calculado del modelo) ── */
.ht1{position:absolute;font-size:70px;font-weight:900;line-height:1;letter-spacing:-2px;color:#1e293b;white-space:nowrap}
.ht2{position:absolute;font-size:70px;font-weight:900;line-height:1;letter-spacing:-2px;color:#2DB140;white-space:nowrap}
/* Línea azul post-título */
.htl{position:absolute;height:3px;background:#1A3A8F;border-radius:2px}
/* ── Párrafos: font=16px, lh=1.85, justify, sangría=38px izq/der ── */
.ptxt{position:absolute;font-size:16px;color:#1e293b;line-height:1.85;text-align:justify}
.ptxt strong{font-weight:700}
.ptxt p{margin-bottom:20px}
.ptxt p:last-child{margin-bottom:0}
/* ── ISO circles: diameter=98px ── */
.iso-c{width:98px;height:98px;border-radius:50%;background:#fff;border:2.5px solid #1A3A8F;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:5px;flex-shrink:0}
.iso-c .il{font-size:8.5px;font-weight:900;color:#1A3A8F;letter-spacing:1px}
.iso-c .in{font-size:15px;font-weight:900;color:#1A3A8F;line-height:1.1}
.iso-c .it{font-size:6.5px;color:#555;line-height:1.3;padding:0 3px;margin-top:2px}
/* ── P5 Condiciones + Cuentas ── */
/* Condiciones: bullet • + label bold, texto normal 12px */
.ci-lbl{font-size:12px;color:#1e293b;display:flex;align-items:flex-start;gap:5px;margin-bottom:2px;line-height:1.4}
.ci-lbl::before{content:'•';font-size:14px;line-height:1;color:#1e293b;flex-shrink:0;margin-top:0}
.ci-lbl strong{font-weight:700}
.ci-txt{font-size:12px;color:#1e293b;line-height:1.5;padding-left:16px;margin-bottom:7px}
/* CUENTAS BANCARIAS: font=42px, bordes negro */
.cbh{font-size:22px;font-weight:900;color:#1e293b;text-align:center;border-top:2.5px solid #1e293b;border-bottom:2.5px solid #1e293b;padding:4px 0;margin:4px 0 8px;letter-spacing:.5px}
.cbh span{color:#2DB140}
.cbc{font-size:14px;font-weight:700;color:#1A3A8F;text-align:center;margin-bottom:2px}
.cbr{font-size:11px;color:#555;text-align:center;margin-bottom:4px}
.cbt{font-size:13px;font-weight:700;color:#1e293b;text-align:center;margin-bottom:8px;letter-spacing:.3px}
/* Banco logos + texto: Nº de Cuenta bold, font=12px */
.bkn{font-size:12px;color:#1e293b;margin-bottom:3px;line-height:1.45}
.bkn strong{font-weight:700}
/* Asesor: nombre 20px, cargo italic */
.asr-nm{font-size:20px;font-weight:900;color:#1A3A8F;line-height:1;margin-bottom:3px;text-decoration:underline;text-underline-offset:3px}
.asr-cg{font-size:13px;color:#555;font-style:italic;margin-bottom:4px}
.asr-c{font-size:11px;color:#555;margin-bottom:2px;display:flex;align-items:center;gap:5px}
/* ── Firmas P7 ── */
.frs{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin:30px 0}
.frc{text-align:center}
.frs-sp{height:70px}.frl{height:1.5px;background:#94a3b8;margin-bottom:5px}
.frn{font-size:12.5px;font-weight:700;color:#1A3A8F}
.fro{font-size:12.5px;font-weight:700;color:#2DB140;margin-top:1px}
.frr{font-size:11.5px;font-weight:700;color:#1e293b;margin-top:1px}
.dck{width:18px;height:18px;background:#1A3A8F;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px}
.dck svg{width:10px;height:10px;stroke:#fff;stroke-width:3;fill:none}
.ibc{width:90px;height:90px;border-radius:50%;background:#fff;border:2.5px solid #1A3A8F;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:5px}
.ibc .il{font-size:8.5px;font-weight:900;color:#1A3A8F;letter-spacing:1px}
.ibc .in{font-size:14px;font-weight:900;color:#1A3A8F;line-height:1.1}
.ibc .it{font-size:6.5px;color:#555;line-height:1.3;padding:0 4px;margin-top:2px}
/* ── Cards con Sombra (Opción 3) ── */
/* Card contenedor genérico */
.card{background:#fff;border-radius:10px;box-shadow:0 2px 14px rgba(0,0,0,.08);padding:16px 20px}
/* Step cards para metodología */
.step-card{display:flex;align-items:flex-start;gap:12px;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.07);padding:10px 14px;margin-bottom:8px}
.step-num{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:900;color:#fff;flex-shrink:0;margin-top:1px}
.step-body{flex:1;font-size:13.5px;color:#1e293b;line-height:1.65}
.step-who{font-size:10.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;margin-bottom:2px}
/* Condition cards */
.cond-card{background:#f8fafc;border-left:4px solid #1A3A8F;border-radius:0 8px 8px 0;padding:9px 14px;margin-bottom:8px;box-shadow:0 1px 5px rgba(0,0,0,.05)}
.cond-title{font-size:12.5px;font-weight:800;color:#1A3A8F;margin-bottom:3px}
.cond-text{font-size:12px;color:#475569;line-height:1.55}
/* Bank cards */
.bk-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px}
.bk-card{background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:8px;padding:10px 12px;box-shadow:0 1px 5px rgba(0,0,0,.05)}
.bk-card-full{background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:8px;padding:10px 14px;box-shadow:0 1px 5px rgba(0,0,0,.05);text-align:center}
/* @page DEBE estar fuera de @media para que funcione en todos los navegadores */
@page{size:A4 portrait;margin:0}
@media print{
  body{background:#fff!important}
  .ab{display:none!important}
  #pg-wrap{margin:0!important;padding:0!important}
  .pg{
    box-shadow:none!important;
    margin:0!important;
    padding:0!important;
    page-break-after:always!important;
    page-break-inside:avoid!important;
    display:block!important;
    width:794px!important;
    height:1123px!important;
    overflow:hidden!important;
  }
  .pg:last-child{page-break-after:auto!important}
}
/* Escala automática en móvil */
@media(max-width:840px){
  body{overflow-x:hidden;}
  .ab{max-width:100%;border-radius:0;margin:0 0 0 0;flex-wrap:wrap;}
  .pg-scaler{transform-origin:top center;display:block;}
}
</style>
</head>
<body>
<div class="ab">
  <button class="bp" onclick="window.print()"><svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg> Imprimir</button>
  <a href="<?=APP_URL?>/modules/cotizaciones/pdf.php?id=<?=$id?>&dl=1" class="bp" style="text-decoration:none"><svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Descargar PDF</a>
  <a href="<?=APP_URL?>/modules/cotizaciones/ver.php?id=<?=$id?>" class="bb">← Volver</a>
</div>
<?php
/* Footer — texto izq/der con left+width+text-align (Dompdf-safe; alineado a 40px del body) */
function pftr($num,$pg=''){echo'
<div style="position:absolute;bottom:0;left:3px;right:0;height:46px;background:#1e293b;border-top:1px solid rgba(255,255,255,0.07);z-index:4;overflow:hidden">
  <div style="position:absolute;top:17px;left:40px;width:355px;font-size:8.5px;font-weight:600;color:rgba(255,255,255,0.6);letter-spacing:.2px;white-space:nowrap;line-height:1">www.groupglobaliso.com</div>
  <div style="position:absolute;top:17px;left:0;width:751px;font-size:8.5px;font-weight:600;color:rgba(255,255,255,0.6);letter-spacing:.2px;white-space:nowrap;line-height:1;text-align:right">'.($pg!==''?$pg:'').'</div>
</div>';}

/* Header — empresa a la izquierda, número de cotización a la derecha (absolute Dompdf-safe) */
function pg_hdr($num,$fecha,$pg=''){echo'
<div style="position:absolute;top:0;left:0;width:791px;height:56px;background:#1e293b;border-bottom:2px solid #2DB140;overflow:hidden">
  <div style="position:absolute;top:14px;left:40px;width:500px;font-size:11.5px;font-weight:800;color:#fff;letter-spacing:.4px;line-height:1.2;white-space:nowrap">GROUP GLOBAL ISO</div>
  <div style="position:absolute;top:33px;left:40px;width:500px;font-size:8px;color:rgba(255,255,255,0.42);letter-spacing:.3px;line-height:1;white-space:nowrap">Consultor&iacute;a y Certificaci&oacute;n ISO</div>
  <div style="position:absolute;top:22px;left:0;width:751px;font-size:11px;font-weight:700;color:rgba(255,255,255,0.85);letter-spacing:.3px;line-height:1;white-space:nowrap;text-align:right">'.sanitize($num).'</div>
</div>';}
?>

<div id="pg-wrap">

<!-- P1: PORTADA -->
<div class="pg" style="background:#060d1c;overflow:hidden">
  <!-- Foto corporativa: fondo completo -->
  <img src="<?=$IMG_FOTO_CORP?>" alt="" style="position:absolute;top:0;left:0;width:794px;height:1123px;object-fit:cover;object-position:center 30%;z-index:0">
  <!-- Overlay oscuro -->
  <div style="position:absolute;top:0;left:0;width:794px;height:1123px;background:rgba(4,10,22,0.83);z-index:1"></div>
  <!-- Decoración geométrica -->
  <div style="position:absolute;bottom:60px;left:-80px;width:300px;height:300px;border-radius:50%;border:1px solid rgba(255,255,255,0.04);z-index:2"></div>
  <div style="position:absolute;bottom:200px;right:30px;width:140px;height:140px;border-radius:50%;border:1px solid rgba(45,177,64,0.08);z-index:2"></div>
  <!-- Acento verde: borde izquierdo -->
  <div style="position:absolute;top:0;left:0;width:3px;height:1123px;background:#2DB140;z-index:5"></div>

  <!-- Header bar (absolute, alineado a 40px del body) -->
  <div style="position:absolute;top:0;left:0;width:794px;height:56px;background:rgba(0,0,0,0.35);border-bottom:2px solid #2DB140;z-index:10">
    <div style="position:absolute;top:14px;left:40px;width:500px;font-size:11.5px;font-weight:800;color:#fff;letter-spacing:.4px;line-height:1.2;white-space:nowrap">GROUP GLOBAL ISO</div>
    <div style="position:absolute;top:33px;left:40px;width:500px;font-size:8px;color:rgba(255,255,255,0.42);letter-spacing:.3px;line-height:1;white-space:nowrap">Consultoría y Certificación ISO</div>
    <div style="position:absolute;top:22px;left:0;width:754px;font-size:11px;font-weight:700;color:rgba(255,255,255,0.85);letter-spacing:.3px;line-height:1;text-align:right;white-space:nowrap"><?=sanitize($num)?></div>
  </div>

  <!-- Contenido principal: flujo natural — el nombre largo empuja el resto hacia abajo -->
  <div style="position:absolute;top:56px;left:0;right:0;bottom:50px;z-index:10;overflow:hidden;text-align:center">

    <!-- Logo GGI -->
    <div style="margin-top:52px">
      <img src="<?=$IMG_LOGO_COTI?>" alt="GGI" style="height:96px;width:auto;object-fit:contain;display:inline-block;filter:brightness(0) invert(1);opacity:.92">
    </div>

    <!-- Separador decorativo -->
    <div style="margin:14px 0 16px">
      <table style="border-collapse:collapse;margin:0 auto"><tr>
        <td style="vertical-align:middle"><div style="width:60px;height:1px;background:rgba(255,255,255,0.15)"></div></td>
        <td style="padding:0 10px;vertical-align:middle"><div style="width:6px;height:6px;border-radius:50%;background:#2DB140"></div></td>
        <td style="vertical-align:middle"><div style="width:60px;height:1px;background:rgba(255,255,255,0.15)"></div></td>
      </tr></table>
    </div>

    <!-- Título + empresa + normas -->
    <div style="padding:0 40px">
      <div style="font-size:58px;font-weight:900;color:#fff;letter-spacing:-2px;line-height:1">PROPUESTA DE</div>
      <div style="font-size:58px;font-weight:900;color:#2DB140;letter-spacing:-2px;line-height:1;margin-top:4px">CONSULTORÍA ISO</div>
      <div style="height:1px;background:rgba(255,255,255,0.08);margin:18px 60px"></div>
      <div style="font-size:8px;color:rgba(255,255,255,0.3);letter-spacing:3.5px;text-transform:uppercase;margin-bottom:10px">Preparado exclusivamente para</div>
      <div style="font-size:28px;font-weight:900;color:#fff;letter-spacing:-.5px;line-height:1.15"><?=sanitize(strtoupper($cot['nombre_empresa']))?></div>
      <?php if(!empty($normas)):?>
      <div style="margin-top:14px;display:inline-block">
        <table style="border-collapse:collapse"><tr>
          <td style="vertical-align:middle;padding-right:10px">
            <div style="font-size:7.5px;color:rgba(255,255,255,0.35);letter-spacing:2px;text-transform:uppercase;white-space:nowrap">Normas cotizadas</div>
          </td>
          <?php foreach($normas as $ni):?>
          <td style="vertical-align:middle;padding:0 3px">
            <div style="background:rgba(45,177,64,0.15);border:1px solid rgba(45,177,64,0.35);border-radius:4px;padding:4px 10px;font-size:10px;font-weight:800;color:#2DB140;letter-spacing:.3px;white-space:nowrap"><?=sanitize($ni['codigo'])?></div>
          </td>
          <?php endforeach?>
        </tr></table>
      </div>
      <?php endif?>
    </div>

    <!-- Info card -->
    <div style="margin:18px 72px 0">
      <div style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.12);border-top:2px solid rgba(45,177,64,0.5);border-radius:6px;padding:22px 28px">
        <table style="width:100%;border-collapse:collapse"><tr>
          <td style="width:50%;vertical-align:top;padding-right:24px;border-right:1px solid rgba(255,255,255,0.08)">
            <div style="font-size:7px;font-weight:700;color:rgba(45,177,64,0.8);letter-spacing:1.4px;text-transform:uppercase;margin-bottom:4px">FECHA DE EMISIÓN</div>
            <div style="font-size:14px;font-weight:700;color:#fff;margin-bottom:16px"><?=$fecha_larga?></div>
            <div style="font-size:7px;font-weight:700;color:rgba(45,177,64,0.8);letter-spacing:1.4px;text-transform:uppercase;margin-bottom:4px">ASESOR COMERCIAL</div>
            <div style="font-size:13px;font-weight:600;color:rgba(255,255,255,0.9);margin-bottom:3px"><?=sanitize($asesor)?></div>
            <div style="font-size:9px;color:rgba(255,255,255,0.45)"><?=sanitize($asesor_tel)?> · <?=sanitize($asesor_em)?></div>
          </td>
          <td style="width:50%;vertical-align:top;padding-left:24px">
            <div style="font-size:7px;font-weight:700;color:rgba(45,177,64,0.8);letter-spacing:1.4px;text-transform:uppercase;margin-bottom:4px">N° DE COTIZACIÓN</div>
            <div style="font-size:14px;font-weight:700;color:#fff;margin-bottom:16px"><?=sanitize($num)?></div>
            <div style="font-size:7px;font-weight:700;color:rgba(245,158,11,0.9);letter-spacing:1.4px;text-transform:uppercase;margin-bottom:4px">OFERTA VENCE EL</div>
            <div style="font-size:14px;font-weight:800;color:#f59e0b"><?=$fecha_vence?></div>
            <div style="font-size:8.5px;color:rgba(255,255,255,0.28);margin-top:3px">Válida por <?=$dias_validez_txt?></div>
          </td>
        </tr></table>
      </div>
    </div>

    <!-- Certificadoras acreditadas -->
    <div style="margin:16px 40px 0">
      <div style="font-size:8.5px;font-weight:700;color:rgba(255,255,255,0.45);letter-spacing:2px;text-transform:uppercase;text-align:center;margin-bottom:12px">Certificadoras Acreditadas</div>
      <table style="width:100%;border-collapse:collapse"><tr>
        <td style="padding:0 4px">
          <div style="background:#fff;border-radius:8px;padding:14px 8px;text-align:center;box-shadow:0 3px 12px rgba(0,0,0,0.45)">
            <img src="<?=$IMG_QRO?>" alt="QRO" style="height:44px;width:auto;object-fit:contain;display:block;margin:0 auto">
          </div>
        </td>
        <td style="padding:0 4px">
          <div style="background:#fff;border-radius:8px;padding:14px 8px;text-align:center;box-shadow:0 3px 12px rgba(0,0,0,0.45)">
            <img src="<?=$IMG_HAWKEYE?>" alt="Hawk Eye" style="height:44px;width:auto;object-fit:contain;display:block;margin:0 auto">
          </div>
        </td>
        <td style="padding:0 4px">
          <div style="background:#fff;border-radius:8px;padding:14px 8px;text-align:center;box-shadow:0 3px 12px rgba(0,0,0,0.45)">
            <img src="<?=$IMG_QFS?>" alt="QFS" style="height:44px;width:auto;object-fit:contain;display:block;margin:0 auto">
          </div>
        </td>
        <td style="padding:0 4px">
          <div style="background:#fff;border-radius:8px;padding:14px 8px;text-align:center;box-shadow:0 3px 12px rgba(0,0,0,0.45)">
            <img src="<?=$IMG_INGRAIN?>" alt="Ingrain" style="height:44px;width:auto;object-fit:contain;display:block;margin:0 auto">
          </div>
        </td>
        <td style="padding:0 4px">
          <div style="background:#fff;border-radius:8px;padding:14px 8px;text-align:center;box-shadow:0 3px 12px rgba(0,0,0,0.45)">
            <img src="<?=$IMG_SQC?>" alt="SQC" style="height:44px;width:auto;object-fit:contain;display:block;margin:0 auto">
          </div>
        </td>
        <td style="padding:0 4px">
          <div style="background:#fff;border-radius:8px;padding:14px 8px;text-align:center;box-shadow:0 3px 12px rgba(0,0,0,0.45)">
            <img src="<?=$IMG_UKS?>" alt="UKS" style="height:44px;width:auto;object-fit:contain;display:block;margin:0 auto">
          </div>
        </td>
      </tr></table>
    </div>

  </div>

  <!-- Footer bar (absolute, alineado a 40px del body) -->
  <div style="position:absolute;top:1073px;left:0;width:794px;height:50px;background:rgba(0,0,0,0.45);border-top:1px solid rgba(255,255,255,0.07);z-index:10">
    <div style="position:absolute;top:19px;left:40px;width:355px;font-size:8.5px;font-weight:600;color:rgba(255,255,255,0.6);line-height:1;white-space:nowrap">www.groupglobaliso.com</div>
    <div style="position:absolute;top:19px;left:0;width:754px;font-size:8.5px;font-weight:600;color:rgba(255,255,255,0.6);line-height:1;text-align:right;white-space:nowrap">1 / 6</div>
  </div>
</div>

<!-- P2: ACREDITACIÓN Y RESPALDO -->
<div class="pg pg-inner" style="background:#fff">
<div style="position:absolute;top:0;left:0;width:3px;height:1123px;background:#2DB140;z-index:5"></div>
<div style="position:absolute;top:0;left:3px;right:0;height:56px;overflow:hidden"><?php pg_hdr($num,$fecha,'2 / 6')?></div>
<div style="position:absolute;top:56px;left:3px;right:0;height:1021px;overflow:hidden">
  <div style="position:absolute;top:0;left:0;right:0;height:1021px;overflow:hidden;display:flex;flex-direction:column;justify-content:space-between">

    <!-- Hero -->
    <div style="padding:44px 40px 34px;border-bottom:1px solid #e2e8f0">
      <div style="font-size:46px;font-weight:900;line-height:.95;letter-spacing:-1.5px;color:#1e293b">ACREDITACIÓN</div>
      <div style="font-size:46px;font-weight:900;line-height:.95;letter-spacing:-1.5px;color:#2DB140;margin-top:4px">Y RESPALDO</div>
      <table style="margin-top:20px;border-collapse:collapse"><tr>
        <td style="vertical-align:middle;padding-right:14px">
          <img src="<?=$IMG_IAF?>" alt="IAF" style="height:40px;width:auto;object-fit:contain;display:block">
        </td>
        <td style="vertical-align:middle;border-left:1px solid #e2e8f0;padding-left:14px">
          <div style="font-size:12px;color:#475569;line-height:1.65">Miembro activo del <strong style="color:#1e293b">International Accreditation Forum (IAF)</strong></div>
        </td>
      </tr></table>
    </div>

    <!-- Texto de respaldo -->
    <div style="padding:16px 40px 10px">
      <p style="font-size:13px;color:#475569;line-height:1.7;text-align:justify;margin-bottom:10px"><strong style="color:#1e293b">Partners oficiales de organismos de certificación acreditados internacionalmente</strong>, lo que nos permite ofrecer <strong style="color:#1e293b">certificaciones ISO válidas, reconocidas y aceptadas</strong> en auditorías, licitaciones públicas y procesos comerciales a nivel nacional e internacional.</p>
      <p style="font-size:13px;color:#475569;line-height:1.7;text-align:justify">Este respaldo nos posiciona como un <strong style="color:#1e293b">aliado estratégico confiable</strong>, garantizando certificaciones verificables y alineadas a estándares globales, generando total seguridad para tu empresa.</p>
    </div>

    <!-- 4 beneficios -->
    <div style="padding:14px 40px 10px">
      <div style="font-size:9px;font-weight:700;color:#2DB140;letter-spacing:1.2px;text-transform:uppercase;margin-bottom:10px">Respaldo que marca la diferencia</div>
      <table style="width:100%;border-collapse:separate;border-spacing:0 7px">
        <?php
        $bens=[
          ['num'=>'01','color'=>'#1A3A8F','title'=>'Acreditación Internacional','desc'=>'Certificado reconocido por <strong style="color:#1e293b">OSCE</strong> y todas las entidades del Estado peruano.'],
          ['num'=>'02','color'=>'#2DB140','title'=>'Válido en Licitaciones','desc'=>'Aceptado en todos los procesos de <strong style="color:#1e293b">contratación pública</strong> a nivel nacional.'],
          ['num'=>'03','color'=>'#1A3A8F','title'=>'Reconocido Internacionalmente','desc'=>'Avalado por el <strong style="color:#1e293b">IAF</strong> y aceptado en auditorías y procesos comerciales globales.'],
          ['num'=>'04','color'=>'#2DB140','title'=>'Verificable en Línea','desc'=>'Consulta pública en <strong style="color:#1e293b">bases de datos IAF</strong> verificable por cualquier organismo.'],
        ];
        foreach($bens as $b):?>
        <tr>
          <td style="vertical-align:middle;width:44px;padding-right:0">
            <div style="background:<?=$b['color']?>;border-radius:6px 0 0 6px;width:44px;padding:10px 0;text-align:center">
              <div style="font-size:9px;font-weight:900;color:rgba(255,255,255,0.6);line-height:1"><?=$b['num']?></div>
            </div>
          </td>
          <td style="vertical-align:middle;background:#f8fafc;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;border-right:1px solid #e2e8f0;border-radius:0 6px 6px 0;padding:9px 14px">
            <div style="font-size:11.5px;font-weight:800;color:#1e293b;margin-bottom:2px"><?=$b['title']?></div>
            <div style="font-size:10px;color:#475569;line-height:1.5"><?=$b['desc']?></div>
          </td>
        </tr>
        <?php endforeach?>
      </table>
    </div>

    <!-- Organismos de acreditación -->
    <div style="padding:10px 40px 10px">
      <div style="font-size:9px;font-weight:700;color:#2DB140;letter-spacing:1.2px;text-transform:uppercase;margin-bottom:12px">Organismos de Acreditación</div>
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:6px 0">
        <table style="width:100%;border-collapse:collapse"><tr>
          <td style="text-align:center;padding:10px 8px;border-right:1px solid #e2e8f0">
            <img src="<?=$IMG_OECE?>" alt="OECE" style="height:38px;width:auto;object-fit:contain;display:inline-block;vertical-align:middle">
          </td>
          <td style="text-align:center;padding:10px 8px;border-right:1px solid #e2e8f0">
            <img src="<?=$IMG_UAF?>" alt="UAF" style="height:38px;width:auto;object-fit:contain;display:inline-block;vertical-align:middle">
          </td>
          <td style="text-align:center;padding:10px 8px;border-right:1px solid #e2e8f0">
            <img src="<?=$IMG_IAS?>" alt="IAS" style="height:38px;width:auto;object-fit:contain;display:inline-block;vertical-align:middle">
          </td>
          <td style="text-align:center;padding:10px 8px;border-right:1px solid #e2e8f0">
            <img src="<?=$IMG_EUROPEAN?>" alt="EA" style="height:38px;width:auto;object-fit:contain;display:inline-block;vertical-align:middle">
          </td>
          <td style="text-align:center;padding:10px 8px">
            <img src="<?=$IMG_EGAC?>" alt="EGAC" style="height:38px;width:auto;object-fit:contain;display:inline-block;vertical-align:middle">
          </td>
        </tr></table>
      </div>
    </div>

    <!-- Beneficios clave -->
    <div style="padding:10px 40px 30px">
      <div style="font-size:9px;font-weight:700;color:#2DB140;letter-spacing:1.2px;text-transform:uppercase;margin-bottom:10px">Nuestro Diferencial</div>
      <table style="width:100%;border-collapse:collapse">
        <tr>
          <td style="vertical-align:top;padding-right:6px;width:33%">
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-top:3px solid #2DB140;border-radius:0 0 6px 6px;padding:10px 12px 8px">
              <div style="font-size:18px;font-weight:900;color:#2DB140;line-height:1">3 días</div>
              <div style="font-size:11px;font-weight:700;color:#1e293b;margin-top:3px">Tiempo de certificación</div>
              <div style="font-size:9.5px;color:#64748b;line-height:1.5;margin-top:4px">Desde el pago inicial hasta la emisión del certificado oficial.</div>
            </div>
          </td>
          <td style="vertical-align:top;padding:0 3px;width:33%">
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-top:3px solid #1A3A8F;border-radius:0 0 6px 6px;padding:10px 12px 8px">
              <div style="font-size:18px;font-weight:900;color:#1A3A8F;line-height:1">100%</div>
              <div style="font-size:11px;font-weight:700;color:#1e293b;margin-top:3px">Servicio Llave en Mano</div>
              <div style="font-size:9.5px;color:#64748b;line-height:1.5;margin-top:4px">GGI gestiona todo el proceso sin que usted tenga que hacerlo.</div>
            </div>
          </td>
          <td style="vertical-align:top;padding-left:6px;width:33%">
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-top:3px solid #2DB140;border-radius:0 0 6px 6px;padding:10px 12px 8px">
              <div style="font-size:18px;font-weight:900;color:#2DB140;line-height:1">IAF</div>
              <div style="font-size:11px;font-weight:700;color:#1e293b;margin-top:3px">Certificado reconocido</div>
              <div style="font-size:9.5px;color:#64748b;line-height:1.5;margin-top:4px">Aceptado en licitaciones, OSCE y organismos internacionales.</div>
            </div>
          </td>
        </tr>
      </table>
    </div>

  </div>
</div>
<?php pftr($num,'2 / 6')?>
</div>

<!-- P3: EXPERIENCIA & RESULTADOS -->
<div class="pg pg-inner" style="background:#fff">
<div style="position:absolute;top:0;left:0;width:3px;height:1123px;background:#2DB140;z-index:5"></div>
<div style="position:absolute;top:0;left:3px;right:0;height:56px;overflow:hidden"><?php pg_hdr($num,$fecha,'3 / 6')?></div>
<div style="position:absolute;top:56px;left:3px;right:0;height:1021px;overflow:hidden">
  <div style="position:absolute;top:0;left:0;right:0;height:1021px;overflow:hidden;display:flex;flex-direction:column;justify-content:space-between">

    <!-- Hero -->
    <div style="padding:30px 40px 22px;border-bottom:1px solid #e2e8f0">
      <div style="font-size:46px;font-weight:900;line-height:.95;letter-spacing:-1.5px;color:#1e293b">EXPERIENCIA</div>
      <div style="font-size:46px;font-weight:900;line-height:.95;letter-spacing:-1.5px;color:#2DB140;margin-top:4px">& RESULTADOS</div>
      <div style="margin-top:8px;font-size:11px;color:#475569;line-height:1.5">Trayectoria comprobada respaldando a empresas de todo el Perú en sus procesos de certificación ISO</div>
    </div>

    <!-- Estadísticas -->
    <div style="padding:20px 40px 14px">
      <div style="font-size:9px;font-weight:700;color:#2DB140;letter-spacing:1.2px;text-transform:uppercase;margin-bottom:14px">Nuestros Números</div>
      <table style="width:100%;border-collapse:collapse">
        <tr>
          <td style="padding-right:6px">
            <div style="background:#1e293b;border-radius:6px;padding:20px 8px;text-align:center;position:relative;overflow:hidden">
              <div style="position:absolute;bottom:0;left:0;right:0;height:3px;background:#2DB140"></div>
              <div style="font-size:48px;font-weight:900;color:#2DB140;line-height:1;letter-spacing:-1px">542<span style="font-size:28px">+</span></div>
              <div style="font-size:9.5px;font-weight:700;color:#fff;margin-top:7px;text-transform:uppercase;letter-spacing:.5px">Certificaciones ISO</div>
              <div style="font-size:8.5px;color:rgba(255,255,255,.35);margin-top:2px">emitidas a nivel nacional</div>
            </div>
          </td>
          <td style="padding:0 3px">
            <div style="background:#1e293b;border-radius:6px;padding:20px 8px;text-align:center;position:relative;overflow:hidden">
              <div style="position:absolute;bottom:0;left:0;right:0;height:3px;background:#2DB140"></div>
              <div style="font-size:48px;font-weight:900;color:#2DB140;line-height:1;letter-spacing:-1px">488<span style="font-size:28px">+</span></div>
              <div style="font-size:9.5px;font-weight:700;color:#fff;margin-top:7px;text-transform:uppercase;letter-spacing:.5px">Empresas Certificadas</div>
              <div style="font-size:8.5px;color:rgba(255,255,255,.35);margin-top:2px">en todos los sectores</div>
            </div>
          </td>
          <td style="padding:0 3px">
            <div style="background:#1e293b;border-radius:6px;padding:20px 8px;text-align:center;position:relative;overflow:hidden">
              <div style="position:absolute;bottom:0;left:0;right:0;height:3px;background:#2DB140"></div>
              <div style="font-size:48px;font-weight:900;color:#2DB140;line-height:1;letter-spacing:-1px">18<span style="font-size:28px">+</span></div>
              <div style="font-size:9.5px;font-weight:700;color:#fff;margin-top:7px;text-transform:uppercase;letter-spacing:.5px">Departamentos</div>
              <div style="font-size:8.5px;color:rgba(255,255,255,.35);margin-top:2px">con presencia activa</div>
            </div>
          </td>
          <td style="padding-left:6px">
            <div style="background:#1e293b;border-radius:6px;padding:20px 8px;text-align:center;position:relative;overflow:hidden">
              <div style="position:absolute;bottom:0;left:0;right:0;height:3px;background:#2DB140"></div>
              <div style="font-size:48px;font-weight:900;color:#2DB140;line-height:1;letter-spacing:-1px">100<span style="font-size:28px">%</span></div>
              <div style="font-size:9.5px;font-weight:700;color:#fff;margin-top:7px;text-transform:uppercase;letter-spacing:.5px">Soporte Incluido</div>
              <div style="font-size:8.5px;color:rgba(255,255,255,.35);margin-top:2px">acompañamiento integral</div>
            </div>
          </td>
        </tr>
      </table>
    </div>

    <!-- Sectores atendidos -->
    <div style="padding:14px 40px 14px">
      <div style="font-size:9px;font-weight:700;color:#2DB140;letter-spacing:1.2px;text-transform:uppercase;margin-bottom:12px">Sectores que hemos certificado</div>
      <table style="width:100%;border-collapse:collapse">
        <tr>
          <td style="padding:0 5px 7px 0"><div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #2DB140;border-radius:0 4px 4px 0;padding:10px 12px"><div style="font-size:11.5px;font-weight:800;color:#1e293b">Construcción</div><div style="font-size:9.5px;color:#64748b;margin-top:2px">ISO 9001 · ISO 45001</div></div></td>
          <td style="padding:0 5px 7px"><div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #2DB140;border-radius:0 4px 4px 0;padding:10px 12px"><div style="font-size:11.5px;font-weight:800;color:#1e293b">Manufactura</div><div style="font-size:9.5px;color:#64748b;margin-top:2px">ISO 9001 · ISO 14001</div></div></td>
          <td style="padding:0 5px 7px"><div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #2DB140;border-radius:0 4px 4px 0;padding:10px 12px"><div style="font-size:11.5px;font-weight:800;color:#1e293b">Servicios</div><div style="font-size:9.5px;color:#64748b;margin-top:2px">ISO 9001 · ISO 37001</div></div></td>
          <td style="padding:0 0 7px 5px"><div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #2DB140;border-radius:0 4px 4px 0;padding:10px 12px"><div style="font-size:11.5px;font-weight:800;color:#1e293b">Minería</div><div style="font-size:9.5px;color:#64748b;margin-top:2px">ISO 14001 · ISO 45001</div></div></td>
        </tr>
        <tr>
          <td style="padding:0 5px 7px 0"><div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #2DB140;border-radius:0 4px 4px 0;padding:10px 12px"><div style="font-size:11.5px;font-weight:800;color:#1e293b">Salud</div><div style="font-size:9.5px;color:#64748b;margin-top:2px">ISO 9001 · ISO 45001</div></div></td>
          <td style="padding:0 5px 7px"><div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #2DB140;border-radius:0 4px 4px 0;padding:10px 12px"><div style="font-size:11.5px;font-weight:800;color:#1e293b">Logística</div><div style="font-size:9.5px;color:#64748b;margin-top:2px">ISO 9001 · ISO 14001</div></div></td>
          <td style="padding:0 5px 7px"><div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #2DB140;border-radius:0 4px 4px 0;padding:10px 12px"><div style="font-size:11.5px;font-weight:800;color:#1e293b">Tecnología</div><div style="font-size:9.5px;color:#64748b;margin-top:2px">ISO 9001 · ISO 37001</div></div></td>
          <td style="padding:0 0 7px 5px"><div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #2DB140;border-radius:0 4px 4px 0;padding:10px 12px"><div style="font-size:11.5px;font-weight:800;color:#1e293b">Comercio</div><div style="font-size:9.5px;color:#64748b;margin-top:2px">ISO 9001 · ISO 37001</div></div></td>
        </tr>
        <tr>
          <td style="padding:0 5px 7px 0"><div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #2DB140;border-radius:0 4px 4px 0;padding:10px 12px"><div style="font-size:11.5px;font-weight:800;color:#1e293b">Educación</div><div style="font-size:9.5px;color:#64748b;margin-top:2px">ISO 9001 · ISO 50001</div></div></td>
          <td style="padding:0 5px 7px"><div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #2DB140;border-radius:0 4px 4px 0;padding:10px 12px"><div style="font-size:11.5px;font-weight:800;color:#1e293b">Transporte</div><div style="font-size:9.5px;color:#64748b;margin-top:2px">ISO 9001 · ISO 14001</div></div></td>
          <td style="padding:0 5px 7px"><div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #2DB140;border-radius:0 4px 4px 0;padding:10px 12px"><div style="font-size:11.5px;font-weight:800;color:#1e293b">Energía</div><div style="font-size:9.5px;color:#64748b;margin-top:2px">ISO 14001 · ISO 50001</div></div></td>
          <td style="padding:0 0 7px 5px"><div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #2DB140;border-radius:0 4px 4px 0;padding:10px 12px"><div style="font-size:11.5px;font-weight:800;color:#1e293b">Alimentos</div><div style="font-size:9.5px;color:#64748b;margin-top:2px">ISO 9001 · ISO 14001</div></div></td>
        </tr>
        <tr>
          <td style="padding:0 5px 0 0"><div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #2DB140;border-radius:0 4px 4px 0;padding:10px 12px"><div style="font-size:11.5px;font-weight:800;color:#1e293b">Agricultura</div><div style="font-size:9.5px;color:#64748b;margin-top:2px">ISO 14001 · ISO 9001</div></div></td>
          <td style="padding:0 5px"><div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #2DB140;border-radius:0 4px 4px 0;padding:10px 12px"><div style="font-size:11.5px;font-weight:800;color:#1e293b">Seguridad</div><div style="font-size:9.5px;color:#64748b;margin-top:2px">ISO 45001 · ISO 37001</div></div></td>
          <td style="padding:0 5px"><div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #2DB140;border-radius:0 4px 4px 0;padding:10px 12px"><div style="font-size:11.5px;font-weight:800;color:#1e293b">Consultoría</div><div style="font-size:9.5px;color:#64748b;margin-top:2px">ISO 9001 · ISO 37001</div></div></td>
          <td style="padding:0 0 0 5px"><div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:3px solid #2DB140;border-radius:0 4px 4px 0;padding:10px 12px"><div style="font-size:11.5px;font-weight:800;color:#1e293b">Ingeniería</div><div style="font-size:9.5px;color:#64748b;margin-top:2px">ISO 9001 · ISO 45001</div></div></td>
        </tr>
      </table>
    </div>

    <!-- Preguntas Frecuentes -->
    <div style="padding:14px 40px 14px">
      <div style="font-size:9px;font-weight:700;color:#2DB140;letter-spacing:1.2px;text-transform:uppercase;margin-bottom:12px">Preguntas Frecuentes</div>
      <table style="width:100%;border-collapse:collapse">
        <tr>
          <td style="width:50%;vertical-align:top;padding-right:10px">
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:12px 14px;margin-bottom:8px">
              <div style="font-size:11.5px;font-weight:800;color:#1e293b;margin-bottom:5px">¿El certificado es válido para licitaciones del Estado?</div>
              <div style="font-size:10.5px;color:#475569;line-height:1.6">Sí. Nuestros certificados son reconocidos por el OSCE y aceptados en todos los procesos de contratación pública a nivel nacional.</div>
            </div>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:12px 14px">
              <div style="font-size:11.5px;font-weight:800;color:#1e293b;margin-bottom:5px">¿Cuánto tiempo tarda el proceso?</div>
              <div style="font-size:10.5px;color:#475569;line-height:1.6">El certificado se emite en 3 días útiles desde la firma del contrato y el pago inicial, con acompañamiento completo de nuestro equipo especializado.</div>
            </div>
          </td>
          <td style="width:50%;vertical-align:top;padding-left:10px">
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;padding:12px 14px;margin-bottom:8px">
              <div style="font-size:11.5px;font-weight:800;color:#1e293b;margin-bottom:5px">¿Qué garantía tienen de obtener el certificado?</div>
              <div style="font-size:10.5px;color:#475569;line-height:1.6">Nuestra alianza directa con organismos certificadores acreditados internacionalmente nos permite gestionar y coordinar todo el proceso en nombre de su empresa, garantizando la emisión del certificado en el plazo acordado.</div>
            </div>
            <div style="background:#1e293b;border-radius:4px;padding:12px 14px">
              <div style="font-size:10.5px;font-weight:700;color:rgba(255,255,255,0.75);line-height:1.7;margin-bottom:6px">"Nos comprometemos a que su empresa obtenga la certificación ISO de forma eficiente, con el máximo respaldo técnico y en el menor tiempo posible."</div>
              <div style="font-size:9px;color:#2DB140;font-weight:700;letter-spacing:.5px">GROUP GLOBAL ISO S.A.C.</div>
            </div>
          </td>
        </tr>
      </table>
    </div>

    <!-- Normas ISO -->
    <div style="padding:14px 40px 30px">
      <table style="width:100%;border-collapse:collapse"><tr>
        <?php foreach($isos_img as $key=>$url): if($url):?>
        <td style="text-align:center;padding:4px 2px">
          <img src="<?=$url?>" alt="<?=$key?>" style="width:90px;height:90px;object-fit:contain;display:block;margin:0 auto">
        </td>
        <?php endif; endforeach?>
      </tr></table>
    </div>

  </div>
</div>
<?php pftr($num,'3 / 6')?>
</div>

<!-- P5: PROPUESTA ECONÓMICA – diseño factura -->
<?php
$meses_es=['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$ts_base=strtotime($cot['fecha_emision']);
$lbl_a1=$meses_es[(int)date('n',$ts_base)].' '.date('Y',strtotime('+1 year',$ts_base));
$lbl_a2=$meses_es[(int)date('n',$ts_base)].' '.date('Y',strtotime('+2 years',$ts_base));
?>
<div class="pg pg-inner" style="background:#fff">
<div style="position:absolute;top:0;left:0;width:3px;height:1123px;background:#2DB140;z-index:5"></div>
<div style="position:absolute;top:0;left:3px;right:0;height:56px;overflow:hidden"><?php pg_hdr($num,$fecha,'4 / 6')?></div>
<div style="position:absolute;top:56px;left:3px;right:0;height:1021px;overflow:hidden">
  <!-- Contenido -->
  <div style="position:absolute;top:0;left:0;right:0;height:1021px;overflow:hidden;display:flex;flex-direction:column">

    <!-- Hero -->
    <div style="padding:26px 40px 20px;border-bottom:1px solid #e2e8f0;flex-shrink:0">
      <div style="font-size:46px;font-weight:900;line-height:.95;letter-spacing:-1.5px;color:#1e293b">PROPUESTA</div>
      <div style="font-size:46px;font-weight:900;line-height:.95;letter-spacing:-1.5px;color:#2DB140;margin-top:3px">ECONÓMICA</div>
    </div>

    <div style="flex:1;padding:16px 40px 20px;display:flex;flex-direction:column;justify-content:space-between">

    <!-- Descripción GGI -->
    <div style="border-left:3px solid #2DB140;padding:8px 12px;margin-bottom:8px;background:#f8fafc;font-size:11.5px;color:#475569;line-height:1.65">
      <strong style="color:#1e293b">GROUP GLOBAL ISO S.A.C.</strong> es una consultora especializada en certificación y consultoría ISO, con más de 4 años de experiencia acompañando a empresas de diversos sectores en todo el país. Contamos con más de 542 certificados ISO obtenidos, 488 empresas certificadas y presencia en más de 18 departamentos, brindando soluciones eficientes, confiables y orientadas a resultados.
    </div>

    <!-- Cliente: 3 columnas horizontales -->
    <div style="border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;padding:9px 0;margin-bottom:8px">
      <table style="width:100%;border-collapse:collapse"><tr>
        <td style="width:44%;vertical-align:middle;padding-right:16px;border-right:1px solid #e2e8f0">
          <div style="font-size:7px;color:#2DB140;font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:3px">RAZÓN SOCIAL</div>
          <div style="font-size:12.5px;font-weight:800;color:#1e293b;line-height:1.3"><?=sanitize($cot['nombre_empresa'])?></div>
        </td>
        <td style="width:18%;vertical-align:middle;padding:0 16px;border-right:1px solid #e2e8f0">
          <div style="font-size:7px;color:#2DB140;font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:3px">RUC</div>
          <div style="font-size:11.5px;font-weight:700;color:#1e293b"><?=sanitize($cot['ruc_empresa']??'—')?></div>
        </td>
        <td style="width:38%;vertical-align:middle;padding-left:16px">
          <div style="font-size:7px;color:#2DB140;font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:3px">DIRECCIÓN</div>
          <div style="font-size:10px;color:#64748b;line-height:1.4"><?=sanitize($dir_full)?></div>
        </td>
      </tr></table>
    </div>

    <!-- Tabla de servicios: invoice profesional -->
    <table style="width:100%;border-collapse:collapse;font-size:12px">
      <thead>
        <tr style="background:#1A3A8F">
          <th scope="col" style="padding:9px 8px;font-size:9.5px;font-weight:700;color:rgba(255,255,255,.75);letter-spacing:.6px;text-align:center;width:26px;border-right:1px solid rgba(255,255,255,.15)">#</th>
          <th scope="col" style="padding:9px 14px;font-size:9.5px;font-weight:700;color:rgba(255,255,255,.75);letter-spacing:.6px;text-align:left;border-right:1px solid rgba(255,255,255,.15)">DESCRIPCIÓN DEL SERVICIO</th>
          <th scope="col" style="padding:9px 14px;font-size:9.5px;font-weight:700;color:rgba(255,255,255,.75);letter-spacing:.6px;text-align:right;width:105px">IMPORTE (S/)</th>
        </tr>
      </thead>
      <tbody>
        <?php $idx=1; foreach($normas as $n): $bg=($idx%2==0)?'#f8fafc':'#fff';?>
        <tr style="background:<?=$bg?>">
          <td style="padding:9px 8px;text-align:center;font-weight:800;color:#1A3A8F;border-bottom:1px solid #e2e8f0;border-right:1px solid #e2e8f0"><?=$idx++?></td>
          <td style="padding:9px 14px;border-bottom:1px solid #e2e8f0;border-right:1px solid #e2e8f0">
            <span style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:3px;padding:1px 7px;font-size:10.5px;font-weight:800;color:#1A3A8F"><?=sanitize($n['codigo'])?></span>
            <span style="color:#334155;font-weight:600;margin-left:6px"><?=sanitize($n['nombre'])?></span>
          </td>
          <td style="padding:9px 14px;text-align:right;font-weight:800;color:#1e293b;border-bottom:1px solid #e2e8f0;font-size:13px">
            <?=$n['precio']?number_format($n['precio'],2):'<span style="font-size:11px;color:#2DB140;font-weight:700">Inc.</span>'?>
          </td>
        </tr>
        <?php endforeach; $minR=6; for($fi=count($normas);$fi<$minR;$fi++): $bg=($fi%2==0)?'#f8fafc':'#fff';?>
        <tr style="background:<?=$bg?>">
          <td style="padding:9px 8px;text-align:center;color:#e2e8f0;border-bottom:1px solid #e2e8f0;border-right:1px solid #e2e8f0"><?=$fi+1?></td>
          <td style="padding:9px 14px;border-bottom:1px solid #e2e8f0;border-right:1px solid #e2e8f0;color:#e2e8f0">—</td>
          <td style="padding:9px 14px;text-align:right;border-bottom:1px solid #e2e8f0;color:#e2e8f0">—</td>
        </tr>
        <?php endfor?>
        <!-- Totals — 42% derecha, colores sólidos -->
        <tr>
          <td colspan="3" style="padding:0;border-top:1px solid #e2e8f0">
            <table style="width:42%;margin-left:auto;border-collapse:collapse;font-size:10.5px">
              <tr>
                <td style="padding:5px 14px;text-align:right;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0">Subtotal</td>
                <td style="padding:5px 14px;text-align:right;font-weight:700;color:#334155;border-bottom:1px solid #e2e8f0;width:115px"><?=number_format($subtotal,2)?></td>
              </tr>
              <tr style="background:#fff1f2">
                <td style="padding:5px 14px;text-align:right;color:#b91c1c;font-weight:600;border-bottom:1px solid #e2e8f0;white-space:nowrap">
                  Descuento<?php if($descuento>0):?> <span style="background:#b91c1c;color:#fff;font-size:8px;font-weight:700;border-radius:2px;padding:1px 6px;white-space:nowrap"><?=sanitize($campana)?></span><?php endif?>
                </td>
                <td style="padding:5px 14px;text-align:right;font-weight:700;color:#b91c1c;border-bottom:1px solid #e2e8f0;white-space:nowrap"><?=$descuento>0?'- '.number_format($descuento,2):'0.00'?></td>
              </tr>
              <tr>
                <td style="padding:5px 14px;text-align:right;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0">Base Imponible</td>
                <td style="padding:5px 14px;text-align:right;font-weight:700;color:#334155;border-bottom:1px solid #e2e8f0"><?=number_format($base,2)?></td>
              </tr>
              <tr>
                <td style="padding:5px 14px;text-align:right;color:#64748b;font-weight:600;border-bottom:2px solid #1A3A8F">IGV (18%)</td>
                <td style="padding:5px 14px;text-align:right;font-weight:700;color:#334155;border-bottom:2px solid #1A3A8F"><?=number_format($igv,2)?></td>
              </tr>
              <tr style="background:#1A3A8F">
                <td style="padding:11px 14px;text-align:right;font-size:10px;font-weight:700;color:rgba(255,255,255,0.85);letter-spacing:.3px;text-transform:uppercase;white-space:nowrap"><?=$incluye_igv?'TOTAL — IGV INCLUIDO':'TOTAL — SIN IGV'?></td>
                <td style="padding:11px 14px;text-align:right;font-size:20px;font-weight:900;color:#fff;white-space:nowrap">S/ <?=number_format($total,2)?></td>
              </tr>
            </table>
          </td>
        </tr>
      </tbody>
    </table>

    <!-- Cuadro de pagos 50/50 -->
    <table style="width:100%;border-collapse:collapse">
      <tr>
        <td style="width:50%;padding-right:5px;vertical-align:top">
          <div style="border:1px solid #bbf7d0;border-top:3px solid #2DB140;border-radius:4px;padding:10px 14px;background:#f0fdf4">
            <div style="font-size:8.5px;font-weight:700;color:#15803d;letter-spacing:.7px;text-transform:uppercase;margin-bottom:4px">1er Pago — Al firmar contrato</div>
            <div style="font-size:20px;font-weight:900;color:#1e293b;letter-spacing:-.5px">S/ <?=number_format($total/2,2)?></div>
            <div style="font-size:9px;color:#15803d;font-weight:600;margin-top:2px">50% del total · Activa el proyecto</div>
          </div>
        </td>
        <td style="width:50%;padding-left:5px;vertical-align:top">
          <div style="border:1px solid #c7d2fe;border-top:3px solid #1A3A8F;border-radius:4px;padding:10px 14px;background:#eef2ff">
            <div style="font-size:8.5px;font-weight:700;color:#1A3A8F;letter-spacing:.7px;text-transform:uppercase;margin-bottom:4px">2do Pago — Al validar certificados</div>
            <div style="font-size:20px;font-weight:900;color:#1e293b;letter-spacing:-.5px">S/ <?=number_format($total/2,2)?></div>
            <div style="font-size:9px;color:#1A3A8F;font-weight:600;margin-top:2px">50% restante · Al recibir los certificados</div>
          </div>
        </td>
      </tr>
    </table>

    <?php if($ap):?>
    <!-- Auditorías de seguimiento -->
    <div style="border:1px solid #86efac;background:#f0fdf4;padding:10px 14px;margin-bottom:6px">
      <div style="font-size:8px;font-weight:700;color:#2DB140;letter-spacing:.8px;text-transform:uppercase;margin-bottom:7px">Auditorías de Seguimiento</div>
      <table style="width:100%;border-collapse:collapse">
        <tr>
          <td style="width:50%;vertical-align:top;padding-right:12px">
            <div style="font-size:8px;font-weight:700;color:#94a3b8;letter-spacing:.4px;margin-bottom:2px">AUDITORÍA 1 — <?=strtoupper($lbl_a1)?></div>
            <div style="font-size:14px;font-weight:800;color:#1e293b">S/ <?=number_format($ap,2)?></div>
          </td>
          <td style="width:50%;vertical-align:top">
            <div style="font-size:8px;font-weight:700;color:#94a3b8;letter-spacing:.4px;margin-bottom:2px">AUDITORÍA 2 — <?=strtoupper($lbl_a2)?></div>
            <div style="font-size:14px;font-weight:800;color:#1e293b">S/ <?=number_format($ap,2)?></div>
          </td>
        </tr>
      </table>
      <div style="margin-top:7px;padding-top:6px;border-top:1px solid #86efac;font-size:9px;color:#94a3b8;font-style:italic">Costo de auditorías no incluido en el total de certificación. Se factura por separado.</div>
    </div>
    <?php endif?>

    </div><!-- /padding wrapper -->

  </div>

</div>
<?php pftr($num,'4 / 6')?>
</div>

<!-- P5: CONDICIONES COMERCIALES + CUENTAS BANCARIAS -->
<div class="pg pg-inner" style="background:#fff">
<div style="position:absolute;top:0;left:0;width:3px;height:1123px;background:#2DB140;z-index:5"></div>
<div style="position:absolute;top:0;left:3px;right:0;height:56px;overflow:hidden"><?php pg_hdr($num,$fecha,'5 / 6')?></div>
<div style="position:absolute;top:56px;left:3px;right:0;height:1021px;overflow:hidden">
  <div style="position:absolute;top:0;left:0;right:0;height:1021px;overflow:hidden;display:flex;flex-direction:column">

    <!-- Hero -->
    <div style="padding:30px 40px 24px;border-bottom:1px solid #e2e8f0;flex-shrink:0">
      <div style="font-size:46px;font-weight:900;line-height:.95;letter-spacing:-1.5px;color:#1e293b">CONDICIONES</div>
      <div style="font-size:46px;font-weight:900;line-height:.95;letter-spacing:-1.5px;color:#2DB140;margin-top:3px">COMERCIALES</div>
    </div>

    <div style="flex:1;padding:18px 40px 20px;display:flex;flex-direction:column;justify-content:space-between">

    <!-- Condiciones -->
    <table style="width:100%;border-collapse:collapse;margin-bottom:4px">
      <tr>
        <td style="vertical-align:middle;padding-bottom:6px">
          <span style="font-size:9px;font-weight:700;color:#2DB140;letter-spacing:1.2px;text-transform:uppercase"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#2DB140" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/></svg> Condiciones de la Propuesta</span>
        </td>
        <td style="text-align:right;vertical-align:middle;padding-bottom:6px">
          <span style="font-size:8.5px;color:#94a3b8">Vigencia: <strong style="color:#2DB140"><?=$dias_validez_txt?></strong> desde la emisión</span>
        </td>
      </tr>
    </table>
    <div style="border-bottom:1px solid rgba(45,177,64,0.3);margin-bottom:14px"></div>
    <table style="width:100%;border-collapse:collapse;border:1px solid #e2e8f0;border-radius:4px">
      <?php
      $conds=[
        ['<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#1A3A8F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>','Forma de pago','50% a la firma del contrato y 50% restantes al validar los certificados ISO.'],
        ['<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#1A3A8F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>','Plazo de entrega','Certificación en 03 días, contados desde la recepción del pago inicial y la información requerida.'],
        ['<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#1A3A8F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>','Compromiso del cliente','La empresa cliente se compromete a implementar y regularizar los requisitos de la(s) norma(s) ISO aplicable(s) antes de la primera auditoría de seguimiento, facilitando la información y evidencias necesarias.'],
        ['<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>','Incumplimiento del pago','El incumplimiento faculta a GROUP GLOBAL ISO S.A.C. a suspender o retener los certificados hasta la cancelación total del servicio.'],
        ['<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#1A3A8F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>','Documentos requeridos','Ficha RUC, copia literal de la empresa y logo corporativo.'],
      ];foreach($conds as $i=>$c):$last=($i===count($conds)-1);
        $icbg=$i===3?'background:rgba(220,38,38,.06)':'background:rgba(26,58,143,.05)';
      ?>
      <tr>
        <td style="width:155px;padding:14px 10px;vertical-align:middle;border-right:1.5px solid #2DB140;<?=$icbg?>;<?=$last?'':'border-bottom:1px solid #f1f5f9'?>">
          <div style="font-size:11px;font-weight:800;color:#1e293b;line-height:1.5"><?=$c[0]?> <?=$c[1]?></div>
        </td>
        <td style="padding:14px 14px;font-size:11px;color:#475569;line-height:1.65;vertical-align:middle;<?=$last?'':'border-bottom:1px solid #f1f5f9'?>"><?=$c[2]?></td>
      </tr>
      <?php endforeach?>
    </table>

    <!-- Cuentas bancarias -->
    <table style="width:100%;border-collapse:collapse;margin-bottom:4px">
      <tr>
        <td style="vertical-align:middle;padding-bottom:6px">
          <span style="font-size:9px;font-weight:700;color:#2DB140;letter-spacing:1.2px;text-transform:uppercase"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#2DB140" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> Cuentas Bancarias</span>
        </td>
        <td style="text-align:right;vertical-align:middle;padding-bottom:6px">
          <span style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:3px;padding:2px 8px;font-size:8.5px;font-weight:700;color:#1A3A8F">Cuenta Corriente en Soles (S/)</span>
        </td>
      </tr>
    </table>
    <div style="border-bottom:1px solid rgba(45,177,64,0.3);margin-bottom:14px"></div>
    <div style="margin-bottom:12px">
      <span style="font-size:12.5px;font-weight:800;color:#1e293b">GROUP GLOBAL ISO S.A.C</span>
      <span style="font-size:11px;color:#94a3b8;margin-left:8px">RUC: 20615242463</span>
    </div>
    <table style="width:100%;border-collapse:separate;border-spacing:0">
      <tr>
        <td style="width:50%;vertical-align:top;padding-right:7px">
          <div style="border:1px solid #e2e8f0;border-top:3px solid #2DB140;border-radius:4px;padding:18px 16px">
            <img src="<?=$IMG_INTERBANK?>" alt="Interbank" style="height:24px;object-fit:contain;display:block;margin-bottom:12px">
            <div style="font-size:11.5px;color:#475569;margin-bottom:4px">Nº de Cuenta: <strong style="color:#1e293b">200-3007903268</strong></div>
            <div style="font-size:11.5px;color:#475569">CCI: <strong style="color:#1e293b">003-200-003007903268-33</strong></div>
          </div>
        </td>
        <td style="width:50%;vertical-align:top;padding-left:7px">
          <div style="border:1px solid #e2e8f0;border-top:3px solid #0047BB;border-radius:4px;padding:18px 16px">
            <img src="<?=$IMG_BCP?>" alt="BCP" style="height:24px;object-fit:contain;display:block;margin-bottom:12px">
            <div style="font-size:11.5px;color:#475569;margin-bottom:4px">Nº de Cuenta: <strong style="color:#1e293b">1917321593083</strong></div>
            <div style="font-size:11.5px;color:#475569">CCI: <strong style="color:#1e293b">00219100732159308352</strong></div>
          </div>
        </td>
      </tr>
      <tr>
        <td colspan="2" style="padding-top:8px">
          <div style="border:1px solid #e2e8f0;border-top:3px solid #CC0000;border-radius:4px;padding:16px">
            <table style="border-collapse:collapse;width:100%"><tr>
              <td style="vertical-align:middle;padding-right:14px;width:120px">
                <img src="<?=$IMG_BANCONACION?>" alt="Banco de la Nación" style="height:22px;object-fit:contain;display:block">
              </td>
              <td style="vertical-align:middle;border-left:1px solid #e2e8f0;padding-left:14px">
                <span style="font-size:11.5px;color:#475569"><strong style="color:#1e293b">N° de Cuenta Detracción:</strong> 00-059-203142</span>
                <div style="font-size:9px;color:#94a3b8;margin-top:2px">Solo para operaciones de detracción según normativa SUNAT</div>
              </td>
            </tr></table>
          </div>
        </td>
      </tr>
    </table>

    <!-- Asesor -->
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #2DB140;border-radius:0 6px 6px 0;padding:22px 20px">
      <div style="font-size:7px;font-weight:700;color:#2DB140;letter-spacing:1.2px;text-transform:uppercase;margin-bottom:14px">Consultor Asignado</div>
      <table style="width:100%;border-collapse:collapse"><tr>
        <td style="width:80px;vertical-align:middle;padding-right:14px">
          <img src="<?=$_ej_foto_b64?>" alt="Asesor" style="width:68px;height:68px;border-radius:50%;object-fit:cover;object-position:top;display:block;border:2.5px solid #2DB140">
        </td>
        <td style="vertical-align:middle">
          <div style="font-size:15px;font-weight:900;color:#1e293b;margin-bottom:2px"><?=sanitize(strtoupper($asesor))?></div>
          <div style="font-size:10.5px;color:#94a3b8;font-style:italic;margin-bottom:7px">Asesor comercial · Group Global ISO S.A.C.</div>
          <div style="font-size:11px;color:#64748b;margin-bottom:5px"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#2DB140" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg> <?=sanitize($asesor_em)?></div>
          <div style="font-size:11px;color:#64748b"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#2DB140" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 2.18h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.18 6.18l.91-.91a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> <?=sanitize($asesor_tel)?></div>
        </td>
        <td style="vertical-align:middle;padding-left:16px;border-left:1px solid #e2e8f0;text-align:center;width:100px">
          <div style="font-size:8px;color:#94a3b8;margin-bottom:4px">Disponible</div>
          <div style="font-size:10px;font-weight:700;color:#1e293b;margin-bottom:2px">Lun – Sáb</div>
          <div style="font-size:11.5px;font-weight:800;color:#2DB140">9am – 8pm</div>
          <div style="margin-top:6px;font-size:8.5px;color:#94a3b8">Resp. inmediata</div>
        </td>
      </tr></table>
    </div>

    </div><!-- /padding wrapper -->
  </div>
</div>
<?php pftr($num,'5 / 6')?>
</div>

<!-- P6: ACEPTACIÓN DE PROPUESTA -->
<div class="pg pg-inner" style="background:#fff">
<div style="position:absolute;top:0;left:0;width:3px;height:1123px;background:#2DB140;z-index:5"></div>
<div style="position:absolute;top:0;left:3px;right:0;height:56px;overflow:hidden"><?php pg_hdr($num,$fecha,'6 / 6')?></div>
<div style="position:absolute;top:56px;left:3px;right:0;height:1021px;overflow:hidden">
  <div style="position:absolute;top:0;left:0;right:0;height:1021px;overflow:hidden;display:flex;flex-direction:column">

    <!-- Hero -->
    <div style="padding:26px 40px 20px;border-bottom:1px solid #e2e8f0;flex-shrink:0">
      <div style="font-size:46px;font-weight:900;line-height:.95;letter-spacing:-1.5px;color:#1e293b">ACEPTACIÓN DE</div>
      <div style="font-size:46px;font-weight:900;line-height:.95;letter-spacing:-1.5px;color:#2DB140;margin-top:3px">PROPUESTA</div>
      <div style="font-size:11px;color:#94a3b8;margin-top:8px">Autorización formal para el inicio del proceso de certificación ISO</div>
    </div>

    <div style="flex:1;padding:20px 40px 24px;display:flex;flex-direction:column;justify-content:space-between">

      <!-- Texto de aceptación -->
      <div>
        <p style="font-size:12.5px;color:#1e293b;line-height:1.9;text-align:justify;margin-bottom:10px">Representante de la empresa <strong><?=sanitize($cot['nombre_empresa'])?></strong> identificado con <strong>RUC <?=sanitize($cot['ruc_empresa']??'—')?></strong> dirección <strong><?=sanitize(strtoupper($dir_full))?></strong>, habiendo leído y aceptado los términos de la presente propuesta, autorizo a <strong>GROUP GLOBAL ISO S.A.C</strong> a dar inicio el proceso de <strong style="color:#1A3A8F">CERTIFICACIÓN POR EL COSTO DE S/ <?=number_format($total,2)?> <?=$incluye_igv?'INCLUYE IGV':'NO INCLUYE IGV'?></strong>, comprometiéndome a facilitar oportunamente la información y documentación requerida para el proceso.</p>
        <p style="font-size:12.5px;color:#1e293b;line-height:1.9;text-align:justify">Agradecemos dar su conformidad mediante la firma y remisión del presente documento. Con esta aceptación se da inicio al contrato de prestación de servicios entre <strong><?=sanitize($cot['nombre_empresa'])?> – GROUP GLOBAL ISO S.A.C</strong>.</p>
      </div>

      <!-- Firmas (con padding-top y padding-bottom amplios para separar visualmente) -->
      <table style="width:100%;border-collapse:collapse;margin-top:18px">
        <tr>
          <td style="width:50%;text-align:center;padding:24px 20px 32px 0;vertical-align:top">
            <div style="font-size:8px;font-weight:700;color:#94a3b8;letter-spacing:1px;text-transform:uppercase;margin-bottom:8px">FIRMA Y SELLO DEL CLIENTE</div>
            <div style="height:96px"></div>
            <div style="height:1.5px;background:#cbd5e1;margin-bottom:8px"></div>
            <div style="font-size:12px;font-weight:700;color:#1e293b;margin-bottom:3px"><?=sanitize(strtoupper($rep))?></div>
            <div style="font-size:11px;color:#475569;margin-bottom:2px"><?=sanitize($cot['nombre_empresa'])?></div>
            <div style="font-size:10px;color:#94a3b8">RUC: <?=sanitize($cot['ruc_empresa']??'—')?></div>
          </td>
          <td style="width:50%;text-align:center;padding:24px 0 32px 20px;vertical-align:top;border-left:1px dashed #e2e8f0">
            <div style="font-size:8px;font-weight:700;color:#94a3b8;letter-spacing:1px;text-transform:uppercase;margin-bottom:8px">FIRMA Y SELLO GGI</div>
            <?php if(!empty($IMG_FIRMA_GGI)):?>
            <img src="<?=$IMG_FIRMA_GGI?>" alt="Firma GGI" style="height:96px;width:auto;object-fit:contain;display:block;margin:0 auto 4px">
            <?php else:?>
            <div style="height:96px"></div>
            <?php endif?>
            <div style="height:1.5px;background:#cbd5e1;margin-bottom:8px"></div>
            <div style="font-size:12px;font-weight:700;color:#1e293b;margin-bottom:3px">JOSE MENDOZA RONDAN</div>
            <div style="font-size:11px;color:#475569;margin-bottom:2px">GROUP GLOBAL ISO S.A.C</div>
            <div style="font-size:10px;color:#94a3b8">RUC: 20615242463</div>
          </td>
        </tr>
      </table>

      <!-- Documentos requeridos -->
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px">
        <div style="font-size:9px;font-weight:700;color:#2DB140;letter-spacing:1px;text-transform:uppercase;margin-bottom:10px">Documentos Requeridos para Inicio</div>
        <table style="width:100%;border-collapse:collapse"><tr>
          <td style="font-size:11.5px;color:#334155;padding:4px 8px 4px 0;vertical-align:middle">
            <span style="color:#2DB140;font-weight:700;margin-right:5px">&#10003;</span> Ficha RUC y Copia literal.
          </td>
          <td style="font-size:11.5px;color:#334155;padding:4px 8px;vertical-align:middle;border-left:1px solid #e2e8f0">
            <span style="color:#2DB140;font-weight:700;margin-right:5px">&#10003;</span> Alcance de la empresa.
          </td>
          <td style="font-size:11.5px;color:#334155;padding:4px 8px;vertical-align:middle;border-left:1px solid #e2e8f0">
            <span style="color:#2DB140;font-weight:700;margin-right:5px">&#10003;</span> Logo de la empresa.
          </td>
          <td style="font-size:11.5px;color:#334155;padding:4px 0 4px 8px;vertical-align:middle;border-left:1px solid #e2e8f0">
            <span style="color:#2DB140;font-weight:700;margin-right:5px">&#10003;</span> Personal de la empresa (cargos y áreas).
          </td>
        </tr></table>
      </div>

      <!-- Próximos pasos post-firma -->
      <div style="border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px">
        <div style="font-size:11px;font-weight:700;color:#2DB140;letter-spacing:1px;text-transform:uppercase;margin-bottom:14px">Próximos Pasos tras la Aceptación</div>
        <table style="width:100%;border-collapse:collapse">
          <tr>
            <?php
            $pasos=[
              ['01','Firma y envío','Remitir este documento firmado a su asesor.','#1A3A8F'],
              ['02','Pago inicial','Abonar el 50% del total acordado.','#2DB140'],
              ['03','Docs. requeridos','Ficha RUC, copia literal y logo de empresa.','#1A3A8F'],
              ['04','Inicio del proceso','GGI activa el proyecto y asigna consultor dedicado.','#2DB140'],
              ['05','Certificación ISO','Emisión del certificado oficial en 48 h. tras la aprobación.','#1A3A8F'],
            ];
            $total_pasos=count($pasos);
            foreach($pasos as $idx_p=>$p):
              $is_last=($idx_p===$total_pasos-1);
              $is_first=($idx_p===0);
            ?>
            <td style="width:20%;vertical-align:top;padding:0 4px;text-align:center">
              <!-- Banda con conector + círculo (height fija = 30px) -->
              <div style="position:relative;height:30px">
                <?php if(!$is_first):?><div style="position:absolute;top:14px;left:0;width:50%;height:2px;background:#e2e8f0;z-index:0"></div><?php endif?>
                <?php if(!$is_last):?><div style="position:absolute;top:14px;right:0;width:50%;height:2px;background:#e2e8f0;z-index:0"></div><?php endif?>
                <!-- Tabla 1x1 con margin:auto = centrado horizontal Dompdf-safe;
                     vertical-align:middle + text-align:center = centrado del número -->
                <table style="margin:0 auto;border-collapse:collapse;position:relative;z-index:1">
                  <tr><td style="width:30px;height:30px;border-radius:50%;background:<?=$p[3]?>;color:#fff;font-size:11px;font-weight:900;text-align:center;vertical-align:middle;padding:0;line-height:1"><?=$p[0]?></td></tr>
                </table>
              </div>
              <div style="font-size:10.5px;font-weight:700;color:#1e293b;margin:7px 0 4px"><?=$p[1]?></div>
              <div style="font-size:9.5px;color:#64748b;line-height:1.45"><?=$p[2]?></div>
            </td>
            <?php endforeach?>
          </tr>
        </table>
      </div>

      <!-- Nota validez -->
      <table style="width:100%;border-collapse:collapse">
        <tr>
          <td style="background:#fffbeb;border:1px solid #fde68a;border-left:3px solid #f59e0b;padding:7px 12px;font-size:10px;color:#78350f;vertical-align:middle">
            Esta propuesta tiene validez de <strong><?=$dias_validez_txt?></strong> a partir del <strong><?=$fecha_larga?></strong>. Vence el <strong><?=$fecha_vence?></strong>.
          </td>
        </tr>
      </table>

      <!-- Protección de datos — pie de página -->
      <div style="padding:8px 0 0;border-top:1px solid #e2e8f0">
        <p style="font-size:9.5px;color:#94a3b8;line-height:1.6;margin:0"><strong style="color:#64748b">Protección de datos:</strong> Los datos proporcionados serán tratados conforme a la Ley N° 29733 de Protección de Datos Personales del Perú, siendo utilizados exclusivamente para la gestión del proceso de certificación.</p>
      </div>

    </div><!-- /content -->
  </div>
</div>
<?php pftr($num,'6 / 6')?>
</div>
</div><!-- /#pg-wrap -->

<script>
if(new URLSearchParams(window.location.search).get('print')==='1')window.onload=()=>window.print();

// Escalar páginas A4 para que quepan en pantalla sin scroll horizontal
function scalePdf(){
  var pages=document.querySelectorAll('.pg');
  var vw=Math.min(window.innerWidth,840);
  var scale=vw>=826?1:Math.max(0.35,(vw-16)/794);
  pages.forEach(function(p){
    p.style.transformOrigin='top center';
    p.style.transform=scale<1?'scale('+scale+')':'';
    p.style.marginBottom=scale<1?((1123*scale-1123+16)+'px'):'16px';
    p.style.marginTop=scale<1?'8px':'16px';
  });
  var wrap=document.querySelector('#pg-wrap');
  if(wrap&&scale<1)wrap.style.height=(pages.length*(1123*scale+24))+'px';
  else if(wrap)wrap.style.height='';
}
window.addEventListener('resize',scalePdf);
document.addEventListener('DOMContentLoaded',scalePdf);
</script>
</body>
</html>
<?php
if ($_generar_pdf) {
    $html = ob_get_clean();

    require_once __DIR__ . '/../../vendor/autoload.php';

    $options = new \Dompdf\Options();
    // No necesitamos cargar recursos remotos (todo va en data-URI base64)
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'Helvetica');
    // 'screen': ignora @media print (no sobreescribe nuestro @page exacto)
    $options->set('defaultMediaType', 'screen');
    // 96 DPI: A4 = 794×1123 CSS px = 595.5×842.25 pt  (1px = 0.75pt)
    $options->set('dpi', 96);
    $options->set('chroot', [__DIR__ . '/../../', sys_get_temp_dir()]);

    // ── Optimizaciones de rendimiento ───────────────────────────────────────
    // Sin JS, sin PHP embebido, sin font-subsetting (este último es el más caro)
    $options->set('isJavascriptEnabled', false);
    $options->set('isPhpEnabled', false);
    $options->set('isFontSubsettingEnabled', false);

    // Subir límites para PDFs grandes (evita timeouts)
    @ini_set('max_execution_time', '120');
    @ini_set('memory_limit', '512M');

    $dompdf = new \Dompdf\Dompdf($options);

    // ── 1. Limpiar elementos de UI y media queries ───────────────────────────
    $html = preg_replace('/<div\s+class="ab">[\s\S]*?<\/div>/s', '', $html);
    $html = preg_replace('/<script[\s\S]*?<\/script>/si', '', $html);
    $html = preg_replace('/@media\s+print\s*\{[^}]*(\{[^}]*\}[^}]*)?\}/s', '', $html);

    // ── 1.a Forzar fuente DejaVu Sans en cualquier &#10003; (✓), &#10004; (✔) u otros
    //         glifos Unicode fuera de Latin-1. Helvetica los pinta como '?'.
    $html = preg_replace_callback(
        '/&#(10003|10004|10007|10008|9989|9888|10148);/',
        fn($m) => '<span style="font-family:\'DejaVu Sans\',sans-serif">' . $m[0] . '</span>',
        $html
    );

    // ── 1.b Sustituir SVG inline por <img src="data:image/svg+xml;base64,...">
    //         CAUSA RAÍZ: Dompdf 3.1 NO renderiza <svg> inline (solo via <img>).
    //         Diseñamos iconos estilo Heroicons-solid (fill puro, sin stroke) que el
    //         renderer SVG-as-image de Dompdf maneja perfectamente.
    //
    //         Plantillas SVG con viewBox PEQUEÑO (0 0 11 11) y paths reescalados a ese
    //         espacio. Esto evita que Dompdf renderice al tamaño del viewBox 24x24
    //         (problema observado: respetaba viewBox e ignoraba width/height del <img>).
    //         (placeholders __C__ = color, __S__ = tamaño px del render)
    $tpl = [
        // Tarjeta de crédito — Forma de pago / header Cuentas
        'card'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 11 11" width="__S__" height="__S__"><rect x="0.5" y="2.5" width="10" height="6" rx="1" fill="__C__"/><rect x="0.5" y="4.2" width="10" height="1.4" fill="#fff"/><rect x="2" y="6.8" width="2.8" height="0.9" fill="#fff"/></svg>',
        // Reloj — Plazo de entrega
        'clock'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 11 11" width="__S__" height="__S__"><circle cx="5.5" cy="5.5" r="4.6" fill="__C__"/><rect x="5.1" y="2.7" width="0.85" height="3.3" fill="#fff"/><rect x="5.3" y="5.1" width="2.8" height="0.85" fill="#fff"/></svg>',
        // Escudo con check — Compromiso
        'shield' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 11 11" width="__S__" height="__S__"><path d="M5.5 0.5 L1.8 1.9 V5 C1.8 7.5 3.4 9.4 5.5 10.3 C7.6 9.4 9.2 7.5 9.2 5 V1.9 Z" fill="__C__"/><path d="M3.7 5.5 L4.8 6.6 L7.2 4.2 L7.8 4.8 L4.8 7.7 L3.1 6 Z" fill="#fff"/></svg>',
        // Triángulo de alerta — Incumplimiento
        'alert'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 11 11" width="__S__" height="__S__"><path d="M5.5 0.5 L0.5 9.5 L10.5 9.5 Z" fill="__C__"/><rect x="5" y="3.5" width="1" height="3" fill="#fff"/><rect x="5" y="7.3" width="1" height="1" fill="#fff"/></svg>',
        // Documento — Documentos requeridos
        'doc'    => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 11 11" width="__S__" height="__S__"><path d="M6.5 0.5 H2.5 C1.95 0.5 1.5 0.95 1.5 1.5 V9.5 C1.5 10.05 1.95 10.5 2.5 10.5 H8.5 C9.05 10.5 9.5 10.05 9.5 9.5 V3.5 L6.5 0.5 Z" fill="__C__"/><rect x="3" y="6" width="5" height="0.7" fill="#fff"/><rect x="3" y="7.3" width="3.5" height="0.7" fill="#fff"/></svg>',
        // Clipboard — header sección Condiciones (pequeño)
        'clip'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 11 11" width="__S__" height="__S__"><rect x="2" y="2" width="7" height="8.5" rx="0.6" fill="__C__"/><rect x="4" y="1" width="3" height="2" rx="0.4" fill="__C__"/><rect x="3.3" y="5.5" width="4.4" height="0.7" fill="#fff"/><rect x="3.3" y="7" width="3.2" height="0.7" fill="#fff"/></svg>',
        // Sobre — Email
        'mail'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 11 11" width="__S__" height="__S__"><rect x="0.5" y="2.5" width="10" height="6" rx="0.5" fill="__C__"/><polygon points="0.5,2.5 10.5,2.5 5.5,4.5" fill="#fff"/></svg>',
        // Teléfono
        'phone'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 11 11" width="__S__" height="__S__"><path d="M9.2 7.8 V9.2 C9.2 9.4 9 9.6 8.8 9.6 C4.5 9.6 1.4 6.5 1.4 2.2 C1.4 2 1.6 1.8 1.8 1.8 H3.2 C3.4 1.8 3.6 2 3.6 2.2 C3.6 2.8 3.7 3.4 3.9 3.9 C3.95 4.05 3.9 4.2 3.8 4.3 L3.2 4.95 C3.9 6.05 4.95 7.1 6.05 7.8 L6.7 7.2 C6.8 7.1 6.95 7.05 7.1 7.1 C7.6 7.3 8.2 7.4 8.8 7.4 C9 7.4 9.2 7.6 9.2 7.8 Z" fill="__C__"/></svg>',
        // Info — círculo i
        'info'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 11 11" width="__S__" height="__S__"><circle cx="5.5" cy="5.5" r="4.6" fill="__C__"/><rect x="5" y="4.6" width="1" height="3.2" fill="#fff"/><circle cx="5.5" cy="3.4" r="0.6" fill="#fff"/></svg>',
        // Imprimir
        'print'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 11 11" width="__S__" height="__S__"><rect x="2.7" y="0.9" width="5.5" height="2.7" fill="__C__"/><rect x="1.4" y="4.1" width="8.2" height="4.1" rx="0.7" fill="__C__"/><rect x="2.7" y="6.4" width="5.5" height="3.2" fill="#fff"/></svg>',
        // Descargar
        'down'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 11 11" width="__S__" height="__S__"><path d="M5 1.4 H6 V6 L7.8 4.1 L8.5 4.8 L5.5 7.8 L2.5 4.8 L3.2 4.1 L5 6 Z" fill="__C__"/><rect x="1.8" y="8.7" width="7.4" height="0.9" fill="__C__"/></svg>',
    ];

    // Mapping: trozo único del SVG original → [icono, color]
    $icon_map = [
        'M9 5H7a2 2 0 0 0-2 2'                                  => ['clip',   '#2DB140'], // header Condiciones
        'rect x="1" y="4" width="22" height="16"'               => ['card',   '#1A3A8F'], // tarjeta + header Cuentas
        'polyline points="12 6 12 12 16 14"'                    => ['clock',  '#1A3A8F'], // reloj (plazo)
        'M12 22s8-4 8-10V5'                                     => ['shield', '#1A3A8F'], // escudo
        'M10.29 3.86'                                           => ['alert',  '#dc2626'], // alerta
        'M14 2H6a2 2 0 0 0-2 2v16'                              => ['doc',    '#1A3A8F'], // documento
        'rect x="2" y="4" width="20" height="16"'               => ['mail',   '#2DB140'], // email
        'M22 16.92v3a2 2 0 0 1-2.18'                            => ['phone',  '#2DB140'], // teléfono
        'circle cx="12" cy="12" r="10"/><line x1="12" y1="8"'   => ['info',   '#f59e0b'], // info
        'polyline points="6 9 6 2'                              => ['print',  '#1A3A8F'], // print btn (UI)
        'M21 15v4a2 2 0 0 1-2 2'                                => ['down',   '#1A3A8F'], // download btn (UI)
    ];

    $html = preg_replace_callback('/<svg\b[^>]*>(.*?)<\/svg>/is', function ($m) use ($tpl, $icon_map) {
        $inner = $m[1];
        preg_match('/\bwidth="(\d+(?:\.\d+)?)"/', $m[0], $wm);
        $size_orig = isset($wm[1]) ? (float)$wm[1] : 12.0;
        // Tamaño UNIFORME para todos los iconos de P5 (10px).
        $size = 10;
        $base_style = 'width:' . $size . 'px;height:' . $size . 'px';
        // Tres contextos posibles, distinguibles por el width del SVG original:
        //   width=11  → INLINE junto a texto en la MISMA línea (headers de sección
        //               "Condiciones de la Propuesta", "Cuentas Bancarias").
        //               → inline-block + vertical-align:middle + margin-right.
        //   width=12  → DENTRO de su propia celda con texto en celda contigua
        //               (email/teléfono del consultor).
        //               → block centrado; el <td vertical-align:middle> hace el resto.
        //   width≥13  → EN celda de tabla (filas de Condiciones).
        //               → block centrado; el <td vertical-align:middle;text-align:center> hace el resto.
        if ($size_orig <= 11) {
            // Inline next to text — alinea visualmente con la altura-x del texto
            $img_style = $base_style . ';display:inline-block;vertical-align:middle;margin-right:4px';
        } else {
            // En celda — inline-block para que vertical-align:middle del TD funcione en Dompdf
            $img_style = $base_style . ';display:inline-block;vertical-align:middle';
        }
        foreach ($icon_map as $needle => [$key, $color]) {
            if (strpos($inner, $needle) !== false) {
                $svg = strtr($tpl[$key], ['__C__' => $color, '__S__' => (string)$size]);
                $b64 = base64_encode($svg);
                return '<img src="data:image/svg+xml;base64,' . $b64 . '" width="' . $size
                     . '" height="' . $size . '" style="' . $img_style . '">';
            }
        }
        // Fallback inocuo
        return '';
    }, $html);

    // ── 1.c Marcar los wrappers internos de contenido (P4/P5/P6) con clase
    //         .pdf-stack y aumentar el padding superior/inferior para crear
    //         colchón visual con header/footer (compensa pérdida de space-between).
    $html = preg_replace(
        '/<div\s+style="flex:1;padding:(\d+)px\s+(\d+)px\s+(\d+)px;display:flex;flex-direction:column;justify-content:space-between"/',
        '<div class="pdf-stack" style="padding:26px $2px 22px;display:block"',
        $html
    );

    // ── 2. ELIMINAR FLEX (Dompdf no soporta flex-direction:column) ───────────
    // Estos wrappers envuelven el contenido de P2-P6 y causan el descentrado.
    // Se reemplazan por display:block para que el contenido fluya normalmente.

    // Flex columna con justify-content:space-between (P2, P3 y wrappers anidados que NO se marcaron arriba)
    $html = str_replace(
        'display:flex;flex-direction:column;justify-content:space-between',
        'display:block',
        $html
    );
    // Flex columna sin justify (P4, P5, P6 outer wrapper)
    $html = str_replace(
        'display:flex;flex-direction:column',
        'display:block',
        $html
    );
    // inline-flex en spans (etiquetas de sección en P5)
    $html = str_replace(
        'display:inline-flex;align-items:center;gap:5px',
        'display:inline',
        $html
    );
    // Eliminar flex:1 al inicio de style (P4/P5/P6 inner wrappers)
    $html = str_replace('flex:1;', '', $html);
    $html = str_replace(';flex:1', '', $html);
    // Eliminar flex-shrink (hero sections en P4/P5/P6)
    $html = str_replace(';flex-shrink:0', '', $html);
    $html = str_replace('flex-shrink:0;', '', $html);
    // Eliminar gap (no soportado en Dompdf)
    $html = preg_replace('/;gap:\d+(\.\d+)?px/', '', $html);
    $html = preg_replace('/gap:\d+(\.\d+)?px;/', '', $html);

    // ── 3. PARCHE DE POSICIONAMIENTO ─────────────────────────────────────────
    // Dompdf no resuelve correctamente position:absolute con right:N o bottom:N
    // en contenedores anidados → convertir a top/width explícitos.

    // P1: contenido principal usa top+bottom → convertir a height explícita
    $html = str_replace(
        'position:absolute;top:56px;left:0;right:0;bottom:50px;z-index:10;overflow:hidden;text-align:center',
        'position:absolute;top:56px;left:0;width:794px;height:1017px;z-index:10;overflow:hidden;text-align:center',
        $html
    );

    // Footer interno (pftr): bottom:0 → top:1077px  (1123-46=1077)
    $html = str_replace(
        'position:absolute;bottom:0;left:3px;right:0;height:46px',
        'position:absolute;top:1077px;left:3px;width:791px;height:46px',
        $html
    );

    // Header wrapper páginas internas: right:0 → width explícito
    $html = str_replace(
        'position:absolute;top:0;left:3px;right:0;height:56px;overflow:hidden',
        'position:absolute;top:0;left:3px;width:791px;height:56px;overflow:hidden',
        $html
    );

    // Área de contenido páginas internas (outer)
    $html = str_replace(
        'position:absolute;top:56px;left:3px;right:0;height:1021px;overflow:hidden',
        'position:absolute;top:56px;left:3px;width:791px;height:1021px;overflow:hidden',
        $html
    );

    // Wrapper interno de contenido (posicionado dentro del área de contenido)
    $html = str_replace(
        'position:absolute;top:0;left:0;right:0;height:1021px;overflow:hidden',
        'position:absolute;top:0;left:0;width:791px;height:1021px;overflow:hidden',
        $html
    );

    // ── 4. Eliminar propiedades no soportadas por Dompdf ─────────────────────
    $html = preg_replace('/;object-position:[^;"]+/', '', $html);
    $html = preg_replace('/object-position:[^;"]+;/', '', $html);
    $html = preg_replace('/;backdrop-filter:[^;"]+/', '', $html);
    $html = preg_replace('/backdrop-filter:[^;"]+;/', '', $html);

    // ── 5. Legacy: tira de logos ISO si existe  ───────────────────────────────
    $html = str_replace(
        'position:absolute;bottom:0;left:0;right:0;height:131px',
        'position:absolute;top:890px;left:0;width:100%;height:131px',
        $html
    );

    // ── 6. Firma GGI: transform no soportado → centrado simple ───────────────
    $html = str_replace(
        'max-height:78px;max-width:95%;object-fit:contain;display:inline-block;position:absolute;bottom:4px;left:50%;transform:translateX(-50%)',
        'max-height:60px;max-width:90%;display:block;margin:4px auto 0',
        $html
    );

    // ── 7. Inyectar spacer de altura en cada .pg ─────────────────────────────
    // Dompdf necesita un elemento en flujo normal para calcular la altura del
    // contenedor .pg (todo lo demás es position:absolute, fuera del flujo).
    $spacer = '<div style="display:block;height:1123px;width:0;overflow:hidden;'
            . 'visibility:hidden;font-size:0;line-height:0;margin:0;padding:0"></div>';
    $html = preg_replace_callback(
        '/(<div\b[^>]*\bclass="pg\b[^"]*"[^>]*>)/s',
        fn($m) => $m[1] . $spacer,
        $html
    );

    // ── 8. CSS de corrección global ───────────────────────────────────────────
    $pdf_css = '<style>'
        . '@page{size:595.5pt 842.25pt;margin:0}'
        . 'html,body{margin:0!important;padding:0!important;background:#fff!important}'
        . '.pg{'
        .   'width:794px!important;'
        .   'height:1123px!important;'
        .   'margin:0!important;'
        .   'overflow:hidden!important;'
        .   'page-break-after:always!important;'
        .   'page-break-inside:avoid!important;'
        .   'display:block!important;'
        .   'position:relative!important'
        . '}'
        . '.pg:last-child{page-break-after:auto!important}'
        . '.ab{display:none!important}'
        // Forzar display:block en cualquier flex remanente
        . '.pg div{flex-direction:unset!important}'
        . '.bk-grid{display:block!important}'
        // Espaciado vertical en wrappers internos (compensa pérdida de space-between en Dompdf)
        // OJO: NO forzar display:block aquí — rompe los <table> hijos directos
        // ("Parent table not found for table cell"). Sólo margen.
        . '.pdf-stack > *{margin-bottom:16px!important}'
        . '.pdf-stack > *:last-child{margin-bottom:0!important}'
        // Las tablas dentro del stack deben mantener su propio interior compacto
        . '.pdf-stack table{margin-top:0;margin-left:0;margin-right:0}'
        // Refuerzo de iconos SVG inline en P5: tamaño y alineación dentro de celdas
        . '.pdf-stack svg{display:inline-block;vertical-align:middle}'
        // Header/footer: garantizar que el contenido no invada (no hay overlap, pero
        // forzamos overflow:hidden en el área de contenido por seguridad)
        . '.pg-inner > div[style*="height:1021px"]{overflow:hidden!important}'
        . '</style>';
    $html = str_replace('</head>', $pdf_css . '</head>', $html);

    // Formato: {RUC}_{NUMERO}.pdf  →  20615242463_COT-2026-0000525.pdf
    $safe_ruc     = preg_replace('/[^0-9]/', '', $cot['ruc_empresa'] ?? '');
    $safe_num     = preg_replace('/[^A-Za-z0-9\-_]/', '-', $cot['numero'] ?? 'cotizacion');
    $pdf_filename = $safe_ruc ? $safe_ruc . '_' . $safe_num : $safe_num;

    // ── Caché de PDF: hash del HTML final como clave. Si la cotización no cambió,
    //    los re-downloads sirven el archivo en disco (decenas de ms vs varios segundos).
    $cache_dir = __DIR__ . '/../../storage/cache/pdf/';
    if (!is_dir($cache_dir)) @mkdir($cache_dir, 0755, true);
    $cache_file = $cache_dir . 'cot-' . (int)$cot['id'] . '-' . md5($html) . '.pdf';
    if (is_file($cache_file) && filesize($cache_file) > 1024) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . $_pdf_disp . '; filename="' . $pdf_filename . '.pdf"');
        header('Content-Length: ' . filesize($cache_file));
        header('Cache-Control: private, max-age=0, must-revalidate');
        readfile($cache_file);
        exit;
    }

    try {
        $dompdf->loadHtml($html, 'UTF-8');
        // Papel: 595.5pt × 842.25pt  (= 794px × 1123px a 96dpi, factor 0.75)
        $dompdf->setPaper([0, 0, 595.5, 842.25]);
        $dompdf->render();

        // Persistir en caché (best-effort; ignorar fallos de escritura)
        $pdf_bytes = $dompdf->output();
        @file_put_contents($cache_file, $pdf_bytes);
        // Limpiar entradas viejas del mismo cotID (otros hashes) para no acumular
        foreach (glob($cache_dir . 'cot-' . (int)$cot['id'] . '-*.pdf') ?: [] as $old) {
            if ($old !== $cache_file) @unlink($old);
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . $_pdf_disp . '; filename="' . $pdf_filename . '.pdf"');
        header('Content-Length: ' . strlen($pdf_bytes));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $pdf_bytes;
    } catch (\Throwable $e) {
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8', true, 500);
        }
        echo "Error generando PDF\n\n";
        echo get_class($e) . ': ' . $e->getMessage() . "\n";
        echo $e->getFile() . ':' . $e->getLine() . "\n\n";
        echo $e->getTraceAsString();
    }
    exit;
}
?>