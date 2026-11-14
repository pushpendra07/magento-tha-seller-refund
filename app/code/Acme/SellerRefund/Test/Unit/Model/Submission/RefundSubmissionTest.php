<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Model\Submission;

use Acme\SellerRefund\Exception\ValidationException;
use Acme\SellerRefund\Model\Submission\RefundSubmission;
use PHPUnit\Framework\TestCase;

/**
 * Browser intentions require a token and compare normalized selection details.
 */
class RefundSubmissionTest extends TestCase
{
    /**
     * @return void
     */
    public function testFingerprintIgnoresItemOrderAndDecimalZeroes(): void
    {
        $first = new RefundSubmission('defect', [2 => '1', 1 => '2'], null, str_repeat('a', 32));
        $retry = new RefundSubmission('defect', [1 => '2.0000', 2 => '01.0'], null, str_repeat('a', 32));
        self::assertSame($first->getFingerprint(), $retry->getFingerprint());
        self::assertNotSame($first->getFingerprint(), (new RefundSubmission('defect', [1 => '1']))->getFingerprint());
        $changedReason = new RefundSubmission('other', [1 => '2', 2 => '1']);
        self::assertNotSame($first->getFingerprint(), $changedReason->getFingerprint());
    }

    /**
     * @return void
     */
    public function testBrowserRequestCannotOmitItsToken(): void
    {
        $this->expectException(ValidationException::class);
        RefundSubmission::fromRequest(['reason_code' => 'defect', 'items' => [1 => '1']]);
    }

    /**
     * @return void
     */
    public function testMalformedTokenIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        RefundSubmission::fromRequest(['submission_token' => ['invalid']]);
    }

    /**
     * @return void
     */
    public function testWrongTokenFormatIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        new RefundSubmission('defect', [1 => '1'], null, 'not-a-request-token');
    }

    /**
     * @return void
     */
    public function testBrowserTokenIsPreserved(): void
    {
        $token = str_repeat('b', 32);
        $request = RefundSubmission::fromRequest([
            'submission_token' => $token, 'reason_code' => 'defect', 'items' => [1 => '1'],
        ]);
        self::assertSame($token, $request->submissionToken);
        self::assertNull((new RefundSubmission('defect', [1 => '1']))->submissionToken);
    }

    /**
     * @return void
     */
    public function testInvalidQuantityCannotBeNormalizedIntoAnExistingRequest(): void
    {
        $this->expectException(ValidationException::class);
        (new RefundSubmission('defect', [1 => '1.00001']))->getFingerprint();
    }
}
