<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Submission;

use Acme\SellerRefund\Exception\ValidationException;

/**
 * Immutable description of what the operator asked to refund.
 */
final class RefundSubmission
{
    /**
     * @param array<int, string> $qtyByItem order_item_id => requested quantity (4-dp string)
     */
    public function __construct(
        public readonly string $reasonCode,
        public readonly array $qtyByItem,
        public readonly ?int $actorId = null,
        public readonly ?string $submissionToken = null
    ) {
        if ($submissionToken !== null && preg_match('/^[a-f0-9]{32}$/D', $submissionToken) !== 1) {
            throw new ValidationException(__('The refund request identifier is invalid. Reload the form.'));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromRequest(array $data): self
    {
        $token = $data['submission_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new ValidationException(__('The refund request identifier is missing. Reload the form.'));
        }
        $reasonCode = (string) ($data['reason_code'] ?? '');

        $qtyByItem = [];
        foreach ((array) ($data['items'] ?? []) as $itemId => $qty) {
            if ($qty === null || $qty === '') {
                continue;
            }
            $qtyByItem[(int) $itemId] = (string) $qty;
        }

        $actorId = isset($data['actor_id']) && $data['actor_id'] !== ''
            ? (int) $data['actor_id']
            : null;

        return new self($reasonCode, $qtyByItem, $actorId, $token);
    }

    /**
     * Identify the submitted details independently of item order and decimal zeroes.
     *
     * @return string
     * @throws ValidationException
     */
    public function getFingerprint(): string
    {
        $items = $this->qtyByItem;
        ksort($items, SORT_NUMERIC);
        foreach ($items as $id => $qty) {
            if (preg_match('/^\d+(\.0+)?$/D', $qty) !== 1 || bccomp($qty, '0', 4) <= 0) {
                throw new ValidationException(__('Refund quantities must be positive whole numbers.'));
            }
            $items[$id] = bcadd($qty, '0', 4);
        }

        return hash('sha256', (string) json_encode([$this->reasonCode, $items]));
    }
}
