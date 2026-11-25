<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

/**
 * Rows of the contract of texts of the operations of Delivery (TextRow): the name and the description of a delivery
 * method, in its creation and its change.
 */
final class DeliveryTextRows
{
    private const string CREATE = 'POST /api/v1/admin/delivery-methods';
    private const string REVISE = 'PUT /api/v1/admin/delivery-methods/{id}';

    /**
     * @return iterable<TextRow>
     */
    public static function all(): iterable
    {
        $staff = InputCase::STAFF;
        $body = [
            'name' => 'Kurier standardowy',
            'description' => 'Dostawa w 2–3 dni robocze',
            'priceGross' => 1999,
            'freeFromGross' => null,
            'active' => true,
            'position' => 10,
            'trackingUrlTemplate' => null,
        ];

        foreach ([self::CREATE => [InputContractFixtures::NONE, $body], self::REVISE => ['newDeliveryMethod', [...$body, 'version' => 1]]] as $operation => [$fixture, $request]) {
            yield TextRow::required($operation, $staff, $fixture, 'name', 'Kurier standardowy', $request,
                empty: 'Nazwa jest wymagana.',
                forbidden: 'Nazwa zawiera niedozwolone znaki.',
                wrongType: 'Nazwa jest wymagana.',
                stored: TextRow::column('SELECT name FROM delivery_method WHERE id = :id', ['id' => '{response.id}']));
            yield TextRow::optional($operation, $staff, $fixture, 'description', 'Dostawa w 2–3 dni robocze', $request,
                noValue: '',
                forbidden: 'Opis zawiera niedozwolone znaki.',
                wrongType: 'Opis musi być tekstem.',
                stored: TextRow::column('SELECT description FROM delivery_method WHERE id = :id', ['id' => '{response.id}']));
        }
    }
}
