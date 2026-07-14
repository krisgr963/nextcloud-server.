<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DAV\Service;

use OCA\DAV\Connector\Sabre\GroupableFile;
use SearchDAV\Backend\SearchResult;

class FileGroupingService {

	/**
	 * @param SearchResult[] $searchResults
	 * @return array{0: SearchResult[], 1: int}
	 */
	public function setGroupOnNodes(array $searchResults, array $mimeTypes, int $minGroupSize, int $timespanMinutes): array {
		if (count($mimeTypes) === 0) {
			return [$searchResults, count($searchResults)];
		}

		$timespan = $timespanMinutes * 60;
		$count = count($searchResults);
		$colapsedCount = 0;
		$result = [];
		$groupNumber = 1;
		$i = 0;

		while ($i < $count) {
			$current = $searchResults[$i];

			if (!$this->isNodeGroupable($current, $mimeTypes)) {
				$result[] = $current;
				$i++;
				$colapsedCount++;
				continue;
			}

			$currentTime = $this->getNodeTime($current);
			$isContaminated = false;

			// check if the time window is contaminated by a non-groupable node
			for ($j = $i + 1; $j < $count; $j++) {
				$nextTime = $this->getNodeTime($searchResults[$j]);
				if (abs($currentTime - $nextTime) > $timespan) {
					break;
				}
				if (!$this->isNodeGroupable($searchResults[$j], $mimeTypes)) {
					$isContaminated = true;
					break;
				}
			}

			if ($isContaminated) {
				$result[] = $current;
				$i++;
				$colapsedCount++;
				continue;
			}

			$groupIndexes = [$i];
			$i++;

			// add nodes to group until time window limit is reached
			while ($i < $count) {
				$next = $searchResults[$i];
				$nextTime = $this->getNodeTime($next);

				if (abs($currentTime - $nextTime) > $timespan) {
					break;
				}

				$groupIndexes[] = $i;
				$i++;
			}

			if (count($groupIndexes) < $minGroupSize) {
				foreach ($groupIndexes as $idx) {
					$result[] = $searchResults[$idx];
					$colapsedCount++;
				}
				continue;
			}

			foreach ($groupIndexes as $idx) {
				/** @var GroupableFile $node */
				$node = $searchResults[$idx]->node;
				$node->setGroup($groupNumber);
				$result[] = $searchResults[$idx];
			}
			$groupNumber++;
			$colapsedCount++;
		}

		return [$result, $colapsedCount];
	}

	public function isNodeGroupable(SearchResult $result, array $mimeTypes): bool {
		$node = $result->node;
		return $node instanceof GroupableFile && in_array($node->getNode()->getMimetype(), $mimeTypes, true);
	}

	private function getNodeTime(SearchResult $result): int {
		$node = $result->node;
		if (!$node instanceof GroupableFile) {
			return 0;
		}
		$uploadTime = $node->getNode()->getUploadTime();
		$creationTime = $node->getNode()->getCreationTime();
		$lastModified = $node->getLastModified();
		return max($uploadTime, $creationTime, $lastModified);
	}
}
