<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Tests;

use OCA\BetterSharing\Controller\ApiController;
use OCP\AppFramework\Http;
use OCP\Server;

/** The routes pass every request field through to the services. */
class ApiControllerTest extends IntegrationTestCase {
	private function controller(): ApiController {
		return Server::get(ApiController::class);
	}

	public function testCreateBundleReceivesItsFields(): void {
		$folder = $this->folder($this->a1, 'Api');
		$response = $this->controller()->createBundle(
			$folder->getId(),
			[['type' => 'user', 'id' => $this->a2->getUID()]],
			null, 1, null, 'Api share', 'Hello', 'api-' . $this->suffix,
		);
		$this->assertSame(Http::STATUS_OK, $response->getStatus(), json_encode($response->getData()));
		$this->assertSame('Api share', $response->getData()['name']);
		$this->assertSame('shared', $response->getData()['recipients'][0]['status']);
	}

	public function testPresetRoutesReceiveTheirFields(): void {
		$created = $this->controller()->createPreset('From API', 5, 21, 'Template', false);
		$this->assertSame(Http::STATUS_OK, $created->getStatus(), json_encode($created->getData()));
		$preset = $created->getData();
		$this->assertSame('From API', $preset->getName());
		$this->assertSame(21, $preset->getExpireDays());
		$updated = $this->controller()->updatePreset($preset->getId(), 'Renamed', 1, null, '', true);
		$this->assertSame(Http::STATUS_OK, $updated->getStatus(), json_encode($updated->getData()));
		$this->assertSame('Renamed', $updated->getData()->getName());
		$this->assertTrue($updated->getData()->getPinned());
	}

	public function testUpdateBundleReceivesItsFields(): void {
		$folder = $this->folder($this->a1, 'ApiEdit');
		$bundle = $this->controller()->createBundle($folder->getId(), [['type' => 'user', 'id' => $this->a2->getUID()]], null, 1, null, 'Before', '', 'api-e-' . $this->suffix)->getData();
		$versions = [(string)$bundle['recipients'][0]['id'] => $bundle['recipients'][0]['version']];
		$response = $this->controller()->updateBundle($bundle['id'], 'After', 'New note', 1, null, $versions, []);
		$this->assertSame(Http::STATUS_OK, $response->getStatus(), json_encode($response->getData()));
		$this->assertSame('After', $response->getData()['name']);
		$this->assertSame('New note', $response->getData()['message']);
	}

	public function testUserErrorsComeBackAsBadRequestWithAMessage(): void {
		$response = $this->controller()->createBundle(0, [], null, 1, null, 'x', '', 'api-bad-' . $this->suffix);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertNotEmpty($response->getData()['message']);
	}
}
