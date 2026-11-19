<?php use Sipe\Services\{Profiles,Sanctions}; ?>
<div class="page-heading"><div><span class="eyebrow">HOJA DE VIDA · USUARIO</span><h1><?= e($student['nombres'].' '.$student['apellidos']) ?></h1><p class="muted"><?= e($student['codigo_estudiante']) ?> · <?= e($student['programa']??($student['tipo_persona']==='ADMINISTRATIVO'?'Programa: no aplica':'Sin programa registrado')) ?><?= !empty($student['programa_provisional'])?' · Asignación provisional':'' ?></p></div><a class="button secondary" href="<?= e(url('borrower').'&mode=history') ?>">← Buscar otra persona</a></div>
<section class="card student-card">
    <div><h2>Información del prestatario</h2><p><?= e($student['correo']) ?></p>
    <dl class="detail-grid">
        <div><dt>Rol / grupo de trabajo</dt><dd><?= e($student['tipo_persona']) ?></dd></div>
        <div><dt>Estado en SIPE</dt><dd><?= $student['activo']?'Activo':'Inactivo' ?></dd></div>
        <div><dt>Fotografía</dt><dd>
            <?php if(Profiles::enabled()): ?>
                <img class="borrower-profile-photo" width="140" height="170" src="<?= e(url('photo').'&id='.$student['id']) ?>" alt="">
                <?= e(Profiles::caption($student)) ?>
            <?php else: ?>Sin fotografía autorizada registrada<?php endif ?>
        </dd></div>
        <div><dt>Cédula</dt><dd><?= e($student['cedula']??'Sin información registrada') ?></dd></div>
        <div><dt>Vigencia del carné</dt><dd><?= e(Profiles::date($student['carnet_vigencia']??null)) ?></dd></div>
    </dl>
    <h3>Autorización de recursos</h3><p class="help"><?= $student['tipo_persona']==='ESTUDIANTE'?e(implode(' · ',array_column($allowed,'nombre'))?:'Sin categorías académicas autorizadas'):'Puede solicitar cualquier categoría según la disponibilidad y las condiciones del recurso.' ?></p>
    <p class="help">El QR identifica la ficha; su consulta requiere una sesión del personal.</p>
    <a class="button" href="<?= e(url('borrower').'&person='.$student['id']) ?>">+ Solicitar Recurso</a></div>
    <img width="190" height="190" src="<?= e(url('qr').'&id='.$student['id']) ?>" alt="QR de identificación del prestatario">
</section>
<section class="card"><h2>Reportes y sanciones</h2><?php if(!$sanctions): ?><p class="help">Sin reportes registrados.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Tipo</th><th>Motivo</th><th>Estado</th><th>Fecha</th></tr></thead><tbody><?php foreach($sanctions as $s): ?><tr><td><?= e(Sanctions::typeLabel($s['tipo'])) ?></td><td><?= e($s['motivo']) ?></td><td><?= e($s['estado']) ?></td><td><?= e($s['fecha_registro']) ?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?><?php if($blocked): ?><p class="help"><?= e(Sanctions::BLOCK_MESSAGE) ?></p><?php endif ?></section>
<section class="card"><h2>Historial de préstamos</h2>
    <p class="help">Préstamos activos <?= e($totals['activos']) ?> · Reservas vigentes <?= e($totals['reservas']) ?> · Devoluciones <?= e($totals['devoluciones']) ?></p>
    <?php require ROOT.'/app/Views/table.php' ?>
</section>
