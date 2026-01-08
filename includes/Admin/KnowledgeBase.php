<?php

declare(strict_types=1);

namespace MapaDeRecursos\Admin;

if (! defined('ABSPATH')) {
	exit;
}

class KnowledgeBase {
	public function render(): void {
		if (! current_user_can('mdr_manage')) {
			wp_die(__('No tienes permisos.', 'mapa-de-recursos'));
		}

		?>
		<div class="wrap">
			<h1 class="title is-3"><?php esc_html_e('Knowledge Base', 'mapa-de-recursos'); ?></h1>
			<p class="content" style="margin-bottom:12px;">
				<?php esc_html_e('Repositorio del plugin en GitHub:', 'mapa-de-recursos'); ?>
				<a href="https://github.com/mdamplus/mapa-de-recursos" target="_blank" rel="noopener noreferrer">https://github.com/mdamplus/mapa-de-recursos</a>
			</p>

			<div class="mdr-kb-accordion">
				<?php echo $this->card(__('Carga Manual de Datos', 'mapa-de-recursos'), $this->content_manual()); ?>
				<?php echo $this->card(__('Importación Masiva', 'mapa-de-recursos'), $this->content_import()); ?>
				<?php echo $this->card(__('Ajustes', 'mapa-de-recursos'), $this->content_settings()); ?>
				<?php echo $this->card(__('Informes', 'mapa-de-recursos'), $this->content_informes()); ?>
				<?php echo $this->card(__('Logs', 'mapa-de-recursos'), $this->content_logs()); ?>
			</div>
			<p class="content" style="margin-top:16px;"><?php esc_html_e('Haz clic en cada tarjeta para ver/ocultar el contenido.', 'mapa-de-recursos'); ?></p>
		</div>
		<?php
	}

	private function card(string $title, string $body): string {
		ob_start();
		?>
		<div class="card mdr-kb-card">
			<header class="card-header">
				<p class="card-header-title"><?php echo esc_html($title); ?></p>
			</header>
			<div class="card-content mdr-kb-content" style="display:block;">
				<?php echo $body; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function content_manual(): string {
		ob_start();
		?>
		<p class="mdr-kb-img"><img src="<?php echo esc_url(MAPA_DE_RECURSOS_URL . 'assets/img/zonas.png'); ?>" alt="<?php esc_attr_e('Zonas', 'mapa-de-recursos'); ?>"></p>
		<ol class="content">
			<li><?php esc_html_e('Zonas: crea las zonas geográficas que necesites.', 'mapa-de-recursos'); ?></li>
			<li><?php esc_html_e('Ámbitos y Subcategorías: define los ámbitos y añade subcategorías desde la misma pantalla.', 'mapa-de-recursos'); ?></li>
			<li><?php esc_html_e('Servicios / Iconos: crea tipos de servicio con su icono (sube SVG o imagen).', 'mapa-de-recursos'); ?></li>
			<li><?php esc_html_e('Financiación: crea financiadores con su logo.', 'mapa-de-recursos'); ?></li>
			<li><?php esc_html_e('Entidades: crea entidades con dirección y geolocalización. Usa el botón de geocodificar para rellenar lat/lng.', 'mapa-de-recursos'); ?></li>
			<li><?php esc_html_e('Recursos: vincula a una entidad, ámbito, subcategoría, servicio y financiación. Añade periodo de inicio/fin y contactos múltiples. Marca "Activo"; si la fecha fin ha pasado, se oculta automáticamente.', 'mapa-de-recursos'); ?></li>
		</ol>
		<p class="content"><?php esc_html_e('Shortcode: [mapa_de_recursos] para mostrar el mapa con filtros.', 'mapa-de-recursos'); ?></p>
		<div class="mdr-kb-gallery">
			<img src="<?php echo esc_url(MAPA_DE_RECURSOS_URL . 'assets/img/ambito.png'); ?>" alt="<?php esc_attr_e('Ámbitos', 'mapa-de-recursos'); ?>">
			<img src="<?php echo esc_url(MAPA_DE_RECURSOS_URL . 'assets/img/servicios-iconos.png'); ?>" alt="<?php esc_attr_e('Servicios e iconos', 'mapa-de-recursos'); ?>">
			<img src="<?php echo esc_url(MAPA_DE_RECURSOS_URL . 'assets/img/financiacion.png'); ?>" alt="<?php esc_attr_e('Financiación', 'mapa-de-recursos'); ?>">
			<img src="<?php echo esc_url(MAPA_DE_RECURSOS_URL . 'assets/img/entidades.png'); ?>" alt="<?php esc_attr_e('Entidades', 'mapa-de-recursos'); ?>">
			<img src="<?php echo esc_url(MAPA_DE_RECURSOS_URL . 'assets/img/recurso.png'); ?>" alt="<?php esc_attr_e('Recursos', 'mapa-de-recursos'); ?>">
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function content_import(): string {
		ob_start();
		?>
		<p class="mdr-kb-img"><img src="<?php echo esc_url(MAPA_DE_RECURSOS_URL . 'assets/img/importar.png'); ?>" alt="<?php esc_attr_e('Importar', 'mapa-de-recursos'); ?>"></p>
		<p class="content"><?php esc_html_e('Usa la pestaña "Importar" para cargar CSV o XLSX. Se previsualiza, puedes editar y luego importar. Se evitan duplicados por nombre cuando aplica.', 'mapa-de-recursos'); ?></p>
		<p><strong><?php esc_html_e('Columnas por tipo:', 'mapa-de-recursos'); ?></strong></p>
		<pre><?php echo esc_html($this->bulk_columns_text()); ?></pre>
		<p class="mdr-kb-img"><img src="<?php echo esc_url(MAPA_DE_RECURSOS_URL . 'assets/img/importar-previsualizar.png'); ?>" alt="<?php esc_attr_e('Previsualización', 'mapa-de-recursos'); ?>"></p>
		<?php
		return (string) ob_get_clean();
	}

	private function content_settings(): string {
		ob_start();
		?>
		<p class="mdr-kb-img"><img src="<?php echo esc_url(MAPA_DE_RECURSOS_URL . 'assets/img/ajustes.png'); ?>" alt="<?php esc_attr_e('Ajustes', 'mapa-de-recursos'); ?>"></p>
		<ul class="content">
			<li><?php esc_html_e('Proveedor de mapa: OSM (sin clave) o Mapbox (requiere token).', 'mapa-de-recursos'); ?></li>
			<li><?php esc_html_e('Radio por defecto en km y centro por defecto para fallback.', 'mapa-de-recursos'); ?></li>
			<li><?php esc_html_e('Zona por defecto opcional para prefijar filtros.', 'mapa-de-recursos'); ?></li>
		</ul>
		<?php
		return (string) ob_get_clean();
	}

	private function content_informes(): string {
		ob_start();
		?>
		<p class="mdr-kb-img"><img src="<?php echo esc_url(MAPA_DE_RECURSOS_URL . 'assets/img/informes.png'); ?>" alt="<?php esc_attr_e('Informes', 'mapa-de-recursos'); ?>"></p>
		<p class="content"><?php esc_html_e('Genera un PDF con recursos actualizados por rango de fechas o últimas 24h. Se guarda en uploads y se registra en logs.', 'mapa-de-recursos'); ?></p>
		<?php
		return (string) ob_get_clean();
	}

	private function content_logs(): string {
		ob_start();
		?>
		<p class="mdr-kb-img"><img src="<?php echo esc_url(MAPA_DE_RECURSOS_URL . 'assets/img/logs.png'); ?>" alt="<?php esc_attr_e('Logs', 'mapa-de-recursos'); ?>"></p>
		<p class="content"><?php esc_html_e('La pestaña Logs muestra acciones de CMS, actualizaciones y cambios de datos (entidades, recursos, etc.) con usuario e IP.', 'mapa-de-recursos'); ?></p>
		<?php
		return (string) ob_get_clean();
	}

	private function bulk_columns_text(): string {
		return <<<TXT
Financiadores:
1) nombre*; 2) descripcion

Servicios:
1) nombre*; 2) slug; 3) marker_style

Ámbitos:
1) nombre*; 2) slug

Subcategorías:
1) ambito_nombre*; 2) nombre*; 3) slug

Zonas:
1) nombre*; 2) slug

Entidades:
1) nombre*; 2) telefono; 3) email; 4) direccion; 5) cp; 6) ciudad; 7) provincia; 8) pais; 9) lat; 10) lng; 11) zona_nombre; 12) web

Recursos:
1) entidad_nombre*; 2) ambito_nombre; 3) subcategoria_nombre; 4) recurso_programa*; 5) descripcion; 6) objetivo; 7) destinatarios; 8) periodo_inicio (YYYY-MM-DD); 9) periodo_fin (YYYY-MM-DD);
10) entidad_gestora_nombre; 11) financiacion_nombre; 12) servicio_nombre; 13) activo (1/0);
14+) contactos en grupos de 3 columnas: contacto_nombreN, contacto_emailN, contacto_telN
TXT;
	}
}
