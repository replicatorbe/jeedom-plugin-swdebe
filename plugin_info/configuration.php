<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-cloud"></i> {{Service SWDE}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Délai d'attente des requêtes}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="http_timeout" placeholder="10">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Secondes avant d'abandonner un appel à swde.be. 10 convient dans la quasi-totalité des cas.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Fréquence de rafraîchissement}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="refresh_minutes" placeholder="15" min="15" step="15">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Minutes entre deux lectures du site SWDE. Le plancher est de 15 minutes, pas de son choix : le cron du plugin tourne tous les quarts d'heure (cron15). Une valeur plus basse n'avancerait rien, une valeur plus haute (30, 60) espace les lectures proportionnellement.}}</span>
			</div>
		</div>
	</fieldset>
</form>
