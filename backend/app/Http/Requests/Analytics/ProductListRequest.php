<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

final class ProductListRequest extends AnalyticsDateRangeRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'search' => ['nullable', 'string', 'max:255'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'product_type' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:active,archived,draft'],
            'sort' => ['nullable', 'string', 'in:net_sales,gross_sales,units_sold,orders,title'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    public function search(): ?string
    {
        $search = $this->query('search');

        return is_string($search) && trim($search) !== '' ? trim($search) : null;
    }

    public function vendor(): ?string
    {
        $vendor = $this->query('vendor');

        return is_string($vendor) && trim($vendor) !== '' ? trim($vendor) : null;
    }

    public function productType(): ?string
    {
        $type = $this->query('product_type');

        return is_string($type) && trim($type) !== '' ? trim($type) : null;
    }

    public function status(): ?string
    {
        $status = $this->query('status');

        return is_string($status) && trim($status) !== '' ? trim($status) : null;
    }

    public function sortField(): string
    {
        $sort = (string) $this->query('sort');

        return $sort !== '' ? $sort : 'net_sales';
    }

    public function sortDirection(): string
    {
        $dir = strtolower((string) $this->query('direction'));

        return in_array($dir, ['asc', 'desc'], true) ? $dir : 'desc';
    }

    public function pageNumber(): int
    {
        return max(1, (int) $this->query('page', 1));
    }

    public function perPageCount(): int
    {
        $perPage = (int) $this->query('per_page', 20);

        return max(1, min(100, $perPage));
    }
}
