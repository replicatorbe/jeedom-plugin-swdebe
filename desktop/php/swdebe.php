<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('swdebe');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter un code postal}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>
		<legend><i class="fas fa-list"></i> {{Mes codes postaux surveillés}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucun code postal pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Cliquez sur « Ajouter un code postal » et donnez-lui un nom, par exemple « SWDE Naast ».}}</li>';
			echo '<li>{{Saisissez le code postal belge à surveiller (quatre chiffres).}}</li>';
			echo '<li>{{Enregistrez : les commandes sont créées et le site SWDE est consulté.}}</li>';
			echo '</ol>';
			echo '</div>';
		}
		echo '<div class="input-group" style="margin:5px;">';
		echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
		echo '<div class="input-group-btn">';
		echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
		echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
		echo '</div>';
		echo '</div>';
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($eqLogics as $eqLogic) {
			$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<i class="fas fa-tint" style="font-size:4em;"></i>';
			echo '<br>';
			echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
		?>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-default btn-sm eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i><span class="hidden-xs"> {{Équipement}}</span></a></li>
			<li role="presentation"><a href="#incidenttab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-exclamation-triangle"></i><span class="hidden-xs"> {{Incidents}}</span></a></li>
			<li role="presentation"><a href="#alerttab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-bell"></i><span class="hidden-xs"> {{Alertes}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ========================= ÉQUIPEMENT ========================= -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Nom}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{SWDE Naast}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach (jeeObject::buildTree(null, false) as $object) {
											echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Activer}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
								</div>
								<label class="col-sm-2 control-label">{{Visible}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
								</div>
							</div>
						</fieldset>

						<fieldset>
							<legend><i class="fas fa-map-marker-alt"></i> {{Code postal}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Code postal}}</label>
								<div class="col-sm-3">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="zipcode" placeholder="7062" maxlength="4" pattern="\d{4}">
								</div>
								<div class="col-sm-6">
									<span class="help-block" style="margin:0;">{{Code postal belge à surveiller (quatre chiffres).}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">&nbsp;</label>
								<div class="col-sm-9">
									<a class="btn btn-info" id="bt_swdebeTest"><i class="fas fa-vial"></i> {{Tester le code postal}}</a>
									<a class="btn btn-default" id="bt_swdebeRefresh" title="{{Relit les incidents du code postal enregistré.}}"><i class="fas fa-sync"></i> {{Rafraîchir maintenant}}</a>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">&nbsp;</label>
								<div class="col-sm-9">
									<span id="span_swdebeTestResult"></span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-heartbeat"></i> {{État}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Dernier rafraîchissement}}</label>
								<div class="col-sm-8">
									<span class="form-control-static" id="span_swdebeLastUpdate">-</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Code postal retenu}}</label>
								<div class="col-sm-8">
									<span class="form-control-static" id="span_swdebeZipcode">-</span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ========================== INCIDENTS ========================== -->
			<div role="tabpanel" class="tab-pane" id="incidenttab">
				<br>
				<div class="col-lg-12">
					<div class="alert alert-info" style="margin-bottom:10px;">{{Les incidents SWDE connus pour ce code postal. Cette liste est relue à chaque rafraîchissement.}}</div>
					<div class="table-responsive">
						<table id="table_swdebeIncidents" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th>{{Titre}}</th>
								<th style="width:160px;">{{Perturbation}}</th>
								<th style="width:160px;">{{Début}}</th>
								<th style="width:160px;">{{Fin prévue}}</th>
								<th style="width:80px;">{{Lien}}</th>
							</tr>
						</thead>
						<tbody></tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- ============================ ALERTES =========================== -->
			<div role="tabpanel" class="tab-pane" id="alerttab">
				<br>
				<div class="col-lg-12">
					<div class="alert alert-info" style="margin-bottom:10px;">
						<b>{{Être prévenu dès qu'un nouvel incident apparaît.}}</b>
						{{Une alerte joue une ou plusieurs actions — notification mobile, SMS, message vocal, allumer une lampe — à l'instant où la SWDE publie un nouvel incident pour ce code postal. Les actions sont celles des scénarios Jeedom : vous choisissez n'importe quelle commande d'action ou bloc de message. Un incident résolu puis la même intervention publiée à nouveau sous un autre identifiant côté SWDE compte comme nouveau et re-déclenche.}}
					</div>

					<form class="form-horizontal">
						<div id="div_swdebeNotifications"></div>
					</form>

					<a class="btn btn-default btn-sm" id="bt_swdebeAddNotification"><i class="fas fa-plus-circle"></i> {{Ajouter une alerte}}</a>

					<fieldset style="margin-top:20px;">
						<legend><i class="fas fa-code"></i> {{Jetons utilisables dans le titre et le message}}</legend>
						<div class="table-responsive">
							<table class="table table-bordered table-condensed">
								<tbody>
									<tr><td style="width:170px;"><code>#titre#</code></td><td>{{Le titre de l'incident, tel que la SWDE le publie. Ex. : « 7062 Naast : Une intervention sur notre réseau est en cours ».}}</td></tr>
									<tr><td><code>#perturbation#</code></td><td>{{La nature de la perturbation : « Coupure d'eau, Baisse de pression, Risque d'eau colorée »… séparées par des virgules.}}</td></tr>
									<tr><td><code>#debut#</code></td><td>{{Date et heure de début, format FR : « sam 03/10/2026 09:00 ».}}</td></tr>
									<tr><td><code>#fin#</code></td><td>{{Date et heure de fin prévue, même format.}}</td></tr>
									<tr><td><code>#lieu#</code></td><td>{{Les localités concernées : « Le Roeulx (7070), Mignault (7070), Naast (7062) ».}}</td></tr>
									<tr><td><code>#url#</code></td><td>{{Lien direct vers la page SWDE de l'incident.}}</td></tr>
									<tr><td><code>#equipement#</code></td><td>{{Le nom de cet équipement.}}</td></tr>
									<tr><td><code>#cp#</code></td><td>{{Le code postal surveillé.}}</td></tr>
								</tbody>
							</table>
						</div>
						<span class="help-block" style="margin:0;">{{Les jetons de Jeedom restent utilisables par-dessus : #[Objet][Équipement][Commande]#, variable(), date(), etc.}}</span>
					</fieldset>
				</div>
			</div>

			<!-- ========================== COMMANDES ========================== -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="table-responsive">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:300px;">{{Nom}}</th>
								<th style="width:180px;">{{Type}}</th>
								<th style="width:250px;">{{Paramètres}}</th>
								<th>{{Action}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include_file('core', 'plugin.template', 'js'); ?>
<?php include_file('desktop', 'swdebe', 'js', 'swdebe'); ?>
