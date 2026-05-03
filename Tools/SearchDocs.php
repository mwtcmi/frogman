<?php
namespace FreePBX\modules\Frogman\Tools;
require_once __DIR__ . '/AbstractTool.php';

class SearchDocs extends AbstractTool {
	public function name() { return 'fm_search_docs'; }
	public function description() { return 'Search the Frogman knowledge base for troubleshooting guides and how-to articles. Params: query (required).'; }
	public function validate($params) {
		if (empty($params['query'])) return 'Parameter "query" is required';
		return true;
	}
	public function execute($params, $context) {
		$query = strtolower(trim($params['query']));
		$keywords = preg_split('/\s+/', $query);
		$docsDir = __DIR__ . '/../docs';

		if (!is_dir($docsDir)) return ['query' => $params['query'], 'count' => 0, 'results' => []];

		$results = [];
		foreach (glob($docsDir . '/*.md') as $file) {
			$content = file_get_contents($file);
			$lower = strtolower($content);
			$basename = basename($file, '.md');

			// Score by keyword matches
			$score = 0;
			$matchedLines = [];
			$lines = explode("\n", $content);

			// Title match (first line) is worth more
			$title = trim($lines[0] ?? '', "# \t\n\r");
			foreach ($keywords as $kw) {
				if (stripos($title, $kw) !== false) $score += 10;
			}

			// Body matches
			foreach ($lines as $i => $line) {
				foreach ($keywords as $kw) {
					if (stripos($line, $kw) !== false) {
						$score++;
						$trimmed = trim($line, "# \t\n\r-*");
						if (!empty($trimmed) && count($matchedLines) < 3) {
							$matchedLines[] = $trimmed;
						}
					}
				}
			}

			if ($score > 0) {
				// Extract the sections that are most relevant
				$sections = [];
				$currentSection = '';
				$currentContent = '';
				foreach ($lines as $line) {
					if (preg_match('/^#{1,3}\s+(.+)/', $line, $m)) {
						if ($currentSection) $sections[] = ['title' => $currentSection, 'content' => trim($currentContent)];
						$currentSection = $m[1];
						$currentContent = '';
					} else {
						$currentContent .= $line . "\n";
					}
				}
				if ($currentSection) $sections[] = ['title' => $currentSection, 'content' => trim($currentContent)];

				// Find the most relevant sections
				$relevantSections = [];
				foreach ($sections as $section) {
					$sectionScore = 0;
					foreach ($keywords as $kw) {
						$sectionScore += substr_count(strtolower($section['content']), $kw);
						if (stripos($section['title'], $kw) !== false) $sectionScore += 5;
					}
					if ($sectionScore > 0) {
						$relevantSections[] = [
							'title' => $section['title'],
							'content' => $section['content'],
							'score' => $sectionScore,
						];
					}
				}
				usort($relevantSections, function($a, $b) { return $b['score'] - $a['score']; });

				$results[] = [
					'file' => $basename,
					'title' => $title,
					'score' => $score,
					'matched_lines' => $matchedLines,
					'sections' => array_slice($relevantSections, 0, 3),
				];
			}
		}

		// Sort by relevance
		usort($results, function($a, $b) { return $b['score'] - $a['score']; });

		return [
			'query' => $params['query'],
			'count' => count($results),
			'results' => array_slice($results, 0, 5),
		];
	}
}
