<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class GetCodecs extends AbstractTool {
	public function name() { return 'fm_get_codecs'; }
	public function description() { return 'List configured audio/video codecs.'; }
	public function validate($params) { return true; }
	public function execute($params, $context) {
		$codecs = $this->freepbx->Sipsettings->getCodecs('audio', true); $video = $this->freepbx->Sipsettings->getCodecs('video', true); return ['audio' => $codecs ?: [], 'video' => $video ?: []];
	}
}
