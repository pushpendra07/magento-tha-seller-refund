<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Outbox;

use Acme\SellerRefund\Model\Config;
use Acme\SellerRefund\Model\Erp\BackoffPolicy;
use Acme\SellerRefund\Model\Erp\Exception\ErpTransientException;
use Acme\SellerRefund\Service\RefundProcessor;
use Psr\Log\LoggerInterface;

/**
 * Claims outbox jobs and drives the matching processor step. Transient failures are
 * rescheduled with backoff up to the configured attempt cap; anything else is dead-lettered.
 */
class Dispatcher
{
    public function __construct(
        private readonly Outbox $outbox,
        private readonly RefundProcessor $processor,
        private readonly BackoffPolicy $backoffPolicy,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Claim and process due jobs; with $refundId, only that refund's jobs.
     *
     * @param string|null $op
     * @param int $limit
     * @param int|null $refundId
     * @return int number of jobs processed
     */
    public function run(?string $op, int $limit, ?int $refundId = null): int
    {
        $operations = $op !== null
            ? [$op]
            : [Outbox::OP_CREATE, Outbox::OP_STATUS_CHECK, Outbox::OP_CONFIRM];

        $processed = 0;
        foreach ($operations as $operation) {
            foreach ($this->outbox->claim($operation, $limit, $refundId) as $row) {
                $this->handle($operation, $row);
                $processed++;
            }
        }

        return $processed;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function handle(string $op, array $row): void
    {
        $id = (int) $row['entity_id'];
        $refundId = (int) $row['refund_id'];
        $attempts = (int) $row['attempts'];
        $nextAttempt = $attempts + 1;

        try {
            switch ($op) {
                case Outbox::OP_CREATE:
                    $this->processor->process($refundId, $nextAttempt);
                    break;
                case Outbox::OP_STATUS_CHECK:
                    $this->processor->checkStatus($refundId, $nextAttempt);
                    break;
                case Outbox::OP_CONFIRM:
                    $this->processor->confirm($refundId, $nextAttempt);
                    break;
                default:
                    throw new \InvalidArgumentException(sprintf('Unknown outbox operation "%s".', $op));
            }
            $this->outbox->succeed($id);
        } catch (ErpTransientException $e) {
            $this->outbox->fail($id, $e->getMessage());
            if ($nextAttempt >= $this->config->maxAttempts()) {
                $this->outbox->dead($id);
                $this->markRetriesExhausted($refundId, $op, $nextAttempt);
                return;
            }
            $this->outbox->reschedule($id, $this->backoffPolicy->delaySeconds($nextAttempt, $e->getRetryAfter()));
        } catch (\Throwable $e) {
            $this->outbox->fail($id, $e->getMessage());
            $this->outbox->dead($id);
            $this->logger->error(
                'Refund outbox job failed: ' . $e->getMessage(),
                ['refund_id' => $refundId, 'operation' => $op]
            );
        }
    }

    /**
     * Show on the refund that the outbox stopped retrying this call.
     *
     * Without this the sub-status stays "Retrying" although nothing will retry it until an
     * operator replays it (FRD 14: the UI shows the real state). A failure here is logged; the
     * job is already dead-lettered either way.
     *
     * @param int $refundId
     * @param string $op
     * @param int $attemptNo
     * @return void
     */
    private function markRetriesExhausted(int $refundId, string $op, int $attemptNo): void
    {
        try {
            $this->processor->markRetriesExhausted($refundId, $op, $attemptNo);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Marking exhausted refund retries failed: ' . $e->getMessage(),
                ['refund_id' => $refundId, 'operation' => $op]
            );
        }
    }
}
