<?php

declare(strict_types=1);

namespace OCA\BetterSharing\Tests;

use OCA\BetterSharing\Db\Recipient;
use OCA\BetterSharing\Service\FirmException;
use OCA\BetterSharing\Service\FirmService;
use OCA\BetterSharing\Service\RecipientResolver;
use OCP\Server;

class FirmAndRecipientTest extends IntegrationTestCase {
	private function firms(): FirmService {
		return Server::get(FirmService::class);
	}

	private function resolver(): RecipientResolver {
		return Server::get(RecipientResolver::class);
	}

	public function testUserWithoutFirmIsRefused(): void {
		$loner = $this->user('loner', []);
		$this->expectException(FirmException::class);
		$this->firms()->firmOf($loner);
	}

	public function testUserInTwoFirmsIsRefused(): void {
		$this->addToGroup($this->a2, $this->firmB);
		$this->expectException(FirmException::class);
		$this->firms()->firmOf($this->a2);
	}

	public function testFirmAdminIsSubadminOfFirmGroup(): void {
		$this->assertTrue($this->firms()->isFirmAdmin($this->a1, $this->firmA));
		$this->assertFalse($this->firms()->isFirmAdmin($this->a2, $this->firmA));
	}

	public function testShareableGroupsExcludeSpanningAndOtherFirmGroups(): void {
		$inside = 'in-' . $this->suffix;
		$spanning = 'span-' . $this->suffix;
		$this->group($inside);
		$this->group($spanning);
		$this->addToGroup($this->a2, $inside);
		$this->addToGroup($this->a2, $spanning);
		$this->addToGroup($this->b1, $spanning);
		$groups = array_keys($this->firms()->shareableGroups($this->firmA));
		$this->assertContains($this->firmA, $groups);
		$this->assertContains($inside, $groups);
		$this->assertNotContains($spanning, $groups);
		$this->assertNotContains($this->firmB, $groups);
		$this->assertNotContains('admin', $groups);
	}

	public function testSearchNeverFindsAnotherFirm(): void {
		$byName = $this->resolver()->search($this->a1, $this->firmA, 'B1 ' . $this->suffix, true);
		$this->assertSame([], array_filter($byName, fn ($r) => $r['type'] !== Recipient::KIND_EMAIL));
		$byUid = $this->resolver()->search($this->a1, $this->firmA, $this->b1->getUID(), true);
		$this->assertSame([], $byUid);
		$own = $this->resolver()->search($this->a1, $this->firmA, 'A2 ' . $this->suffix, true);
		$this->assertSame([['type' => 'user', 'id' => $this->a2->getUID(), 'label' => $this->a2->getDisplayName()]], $own);
	}

	public function testAnotherFirmsAccountIsRefusedByAccountId(): void {
		$this->expectException(FirmException::class);
		$this->resolver()->resolve($this->a1, $this->firmA, [['type' => 'user', 'id' => $this->b1->getUID()]], true);
	}

	public function testAnotherFirmsAddressBecomesAnOrdinaryEmailRecipient(): void {
		$email = $this->b1->getEMailAddress();
		$resolved = $this->resolver()->resolve($this->a1, $this->firmA, [['type' => 'email', 'id' => $email]], true);
		$this->assertSame([['kind' => 'email', 'recipient' => $email, 'display' => $email]], $resolved);
		// Same outcome as an address nobody on the server has.
		$search = $this->resolver()->search($this->a1, $this->firmA, $email, true);
		$this->assertSame([['type' => 'email', 'id' => $email, 'label' => $email]], $search);
	}

	public function testOwnFirmAddressBecomesAFirmShare(): void {
		$resolved = $this->resolver()->resolve($this->a1, $this->firmA, [['type' => 'email', 'id' => strtoupper($this->a2->getEMailAddress())]], true);
		$this->assertSame('user', $resolved[0]['kind']);
		$this->assertSame($this->a2->getUID(), $resolved[0]['recipient']);
	}

	public function testOutsideRecipientsRefusedWhenOutsideSharingIsOff(): void {
		$this->expectException(FirmException::class);
		$this->resolver()->resolve($this->a1, $this->firmA, [['type' => 'email', 'id' => 'client@example.com']], false);
	}

	public function testSpanningGroupIsRefused(): void {
		$spanning = 'span-' . $this->suffix;
		$this->group($spanning);
		$this->addToGroup($this->a2, $spanning);
		$this->addToGroup($this->b1, $spanning);
		$this->expectException(FirmException::class);
		$this->resolver()->resolve($this->a1, $this->firmA, [['type' => 'group', 'id' => $spanning]], true);
	}

	public function testUnknownTypeIsRefused(): void {
		$this->expectException(FirmException::class);
		$this->resolver()->resolve($this->a1, $this->firmA, [['type' => 'circle', 'id' => 'x']], true);
	}
}
