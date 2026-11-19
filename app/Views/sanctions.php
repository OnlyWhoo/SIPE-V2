<div class="page-heading"><div><span class="eyebrow">SEGUIMIENTO ADMINISTRATIVO</span><h1>Sanciones</h1><p class="muted">Decisiones documentadas, sin tarifas ni multas automáticas.</p></div></div><div class="notice">Los retrasos superiores a 24 horas requieren revisión. Confirma y cita el reglamento institucional antes de registrar una sanción por mora.</div>
<?php use Sipe\Middleware\Security; use Sipe\Services\Sanctions; ?>
<?php if($formError): ?><p class="notice error" role="alert"><?= e($formError) ?></p><?php endif ?>
<section class="card">
    <h2>Registrar decisión</h2>
    <form class="filters" method="post" action="<?= e(url('sanctions')) ?>">
        <input type="hidden" name="csrf" value="<?= e(Security::token()) ?>">
        <input type="hidden" name="action" value="search">
        <label>Buscar usuario por código institucional
            <input name="code" value="<?= e($code) ?>" maxlength="40" autocomplete="off" required>
        </label>
        <button>Buscar</button>
    </form>
    <?php if($code!=='' && !$person): ?>
        <p class="notice" role="status">No se encontró un usuario con ese código institucional.</p>
    <?php endif ?>
    <?php if($person):
        $type=is_string($draft['tipo']??null)?$draft['tipo']:'MORA';
        $loanId=is_string($draft['prestamo_id']??null)?$draft['prestamo_id']:'';
        $formatDate=static fn(string $value): string => date('d/m/Y H:i',strtotime($value));
        $now=date('Y-m-d H:i:s');
    ?>
        <dl class="detail-grid" aria-label="Usuario encontrado">
            <div><dt>Nombre completo</dt><dd><?= e($person['nombres'].' '.$person['apellidos']) ?></dd></div>
            <div><dt>Código institucional</dt><dd><?= e($person['codigo_estudiante']) ?></dd></div>
            <div><dt>Rol / grupo de trabajo</dt><dd><?= e(ucfirst(strtolower($person['tipo_persona']))) ?></dd></div>
            <div><dt>Estado en SIPE</dt><dd><?= $person['activo']?'Activo':'Inactivo' ?></dd></div>
        </dl>
        <form method="post" action="<?= e($target) ?>" data-single-submit data-sanction>
            <input type="hidden" name="csrf" value="<?= e(Security::token()) ?>">
            <input type="hidden" name="action" value="register">
            <div class="form-grid">
                <div>
                    <label>Seleccionar préstamo relacionado
                        <select name="prestamo_id" aria-describedby="sanctions-loan-help">
                            <option value="">Sin préstamo seleccionado</option>
                            <?php foreach($loans as $loan):
                                $late=$loan['estado']!=='ANULADO' && ($loan['fecha_devolucion']??$now)>$loan['fecha_limite'];
                                $summary=[
                                    $loan['recurso'], 'Ref. '.$loan['codigo_inventario'],
                                    'Entrega '.$formatDate($loan['fecha_entrega']),
                                    'Vencimiento '.$formatDate($loan['fecha_limite']),
                                    'Devolución '.($loan['fecha_devolucion']?$formatDate($loan['fecha_devolucion']):'sin registrar'),
                                    ucfirst(strtolower($loan['estado'])).($late?' · Con retraso':''),
                                ];
                            ?>
                                <option value="<?= e($loan['id']) ?>" <?= $loan['estado']==='ANULADO'?'disabled':'' ?> <?= (string)$loan['id']===$loanId?'selected':'' ?>><?= e(implode(' | ',$summary)) ?></option>
                            <?php endforeach ?>
                        </select>
                        <small class="help" id="sanctions-loan-help"><?= $loans?'Obligatorio para mora.':'Este usuario no tiene préstamos registrados.' ?></small>
                    </label>
                    <p class="help" data-loan-summary role="status" hidden></p>
                </div>
                <label>Motivo
                    <select name="tipo">
                        <?php foreach(['MORA'=>'Mora superior a 24 horas','DANIO'=>Sanctions::typeLabel('DANIO'),'OTRA'=>'Otro incumplimiento autorizado'] as $value=>$label): ?>
                            <option value="<?= e($value) ?>" <?= $type===$value?'selected':'' ?>><?= e($label) ?></option>
                        <?php endforeach ?>
                    </select>
                </label>
                <label>Fundamento y evidencia
                    <textarea name="motivo" required maxlength="5000"><?= e(is_string($draft['motivo']??null)?$draft['motivo']:'') ?></textarea>
                </label>
            </div>
            <button>Registrar sanción</button>
        </form>
    <?php endif ?>
</section>
<section class="card"><h2>Resolver o anular</h2><form method="post" action="<?= e($target) ?>" data-single-submit><input type="hidden" name="csrf" value="<?= e(Security::token()) ?>"><input type="hidden" name="action" value="resolve"><div class="form-grid"><label>Sanción abierta<select name="id" required><option value="">Seleccionar</option><?php foreach($rows as $s): if($s['estado']!=='ABIERTA')continue; ?><option value="<?= e($s['id']) ?>">#<?= e($s['id'].' · '.$s['estudiante'].' · '.Sanctions::typeLabel($s['tipo'])) ?></option><?php endforeach ?></select></label><label>Estado<select name="estado"><option value="RESUELTA">Resuelta</option><option value="ANULADA">Anulada</option></select></label><label>Justificación<textarea name="observaciones" required maxlength="5000"></textarea></label></div><button>Guardar resolución</button></form></section><section class="card"><h2>Historial · últimos 100 registros</h2><?php $columnFormatters=['tipo'=>[Sanctions::class,'typeLabel']]; require ROOT.'/app/Views/table.php' ?></section>
