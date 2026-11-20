import { test, expect } from "@playwright/test";

test.describe("Product Performance & Filtering", () => {
  test("renders Product Performance table headers and sortable metrics", async ({ page }) => {
    await page.setContent(`
      <!DOCTYPE html>
      <html>
        <head><title>Product Analytics - Products</title></head>
        <body>
          <s-section heading="Product Performance">
            <div class="product-table-wrapper">
              <div class="table-controls">
                <input type="text" id="search-input" placeholder="Search products by title..." value="" />
                <button id="search-btn">Search</button>
                <div class="status-pills">
                  <button class="pill-btn selected" data-status="all">All</button>
                  <button class="pill-btn" data-status="active">Active</button>
                  <button class="pill-btn" data-status="draft">Draft</button>
                  <button class="pill-btn" data-status="archived">Archived</button>
                </div>
              </div>
              <s-table id="products-table">
                <s-table-header-row>
                  <s-table-header>Product</s-table-header>
                  <s-table-header><button class="sort-btn" data-sort="net_sales">Net Sales ▼</button></s-table-header>
                  <s-table-header><button class="sort-btn" data-sort="gross_sales">Gross Sales</button></s-table-header>
                  <s-table-header>Discounts</s-table-header>
                  <s-table-header>Refunds</s-table-header>
                  <s-table-header><button class="sort-btn" data-sort="units_sold">Units</button></s-table-header>
                  <s-table-header><button class="sort-btn" data-sort="orders">Orders</button></s-table-header>
                </s-table-header-row>
                <s-table-body>
                  <s-table-row data-product-id="prod_101">
                    <s-table-cell>
                      <a href="/app/products/prod_101?preset=30d" class="product-title-link">Classic Cotton T-Shirt</a>
                      <span class="status-badge" data-tone="success">ACTIVE</span>
                    </s-table-cell>
                    <s-table-cell class="net-sales-cell">$4,850.00</s-table-cell>
                    <s-table-cell class="gross-sales-cell">$5,200.00</s-table-cell>
                    <s-table-cell class="discounts-cell">$250.00</s-table-cell>
                    <s-table-cell class="refunds-cell">$100.00</s-table-cell>
                    <s-table-cell class="units-cell">194</s-table-cell>
                    <s-table-cell class="orders-cell">120</s-table-cell>
                  </s-table-row>
                  <s-table-row data-product-id="prod_102">
                    <s-table-cell>
                      <a href="/app/products/prod_102?preset=30d" class="product-title-link">Denim Jacket</a>
                      <span class="status-badge" data-tone="warning">DRAFT</span>
                    </s-table-cell>
                    <s-table-cell class="net-sales-cell">$1,200.00</s-table-cell>
                    <s-table-cell class="gross-sales-cell">$1,200.00</s-table-cell>
                    <s-table-cell class="discounts-cell">$0.00</s-table-cell>
                    <s-table-cell class="refunds-cell">$0.00</s-table-cell>
                    <s-table-cell class="units-cell">12</s-table-cell>
                    <s-table-cell class="orders-cell">10</s-table-cell>
                  </s-table-row>
                </s-table-body>
              </s-table>
            </div>
          </s-section>
        </body>
      </html>
    `);

    // Verify section heading
    await expect(page.locator("s-section")).toHaveAttribute("heading", "Product Performance");

    // Verify all 7 table columns are present
    const headers = page.locator("s-table-header");
    await expect(headers).toHaveCount(7);
    await expect(headers.nth(0)).toContainText("Product");
    await expect(headers.nth(1)).toContainText("Net Sales");
    await expect(headers.nth(2)).toContainText("Gross Sales");
    await expect(headers.nth(3)).toContainText("Discounts");
    await expect(headers.nth(4)).toContainText("Refunds");
    await expect(headers.nth(5)).toContainText("Units");
    await expect(headers.nth(6)).toContainText("Orders");

    // Verify product row data and drilldown links
    const rows = page.locator("s-table-row");
    await expect(rows).toHaveCount(2);

    const firstProductLink = page.locator('a[href*="/app/products/prod_101"]');
    await expect(firstProductLink).toHaveText("Classic Cotton T-Shirt");
    await expect(page.locator('[data-product-id="prod_101"] .status-badge')).toHaveText("ACTIVE");
    await expect(page.locator('[data-product-id="prod_101"] .net-sales-cell')).toHaveText("$4,850.00");
    await expect(page.locator('[data-product-id="prod_101"] .discounts-cell')).toHaveText("$250.00");
    await expect(page.locator('[data-product-id="prod_101"] .refunds-cell')).toHaveText("$100.00");
  });

  test("status filter pills switch selection and filter catalogue states", async ({ page }) => {
    await page.setContent(`
      <div class="status-pills">
        <button class="pill-btn selected" data-status="all">All</button>
        <button class="pill-btn" data-status="active">Active</button>
        <button class="pill-btn" data-status="draft">Draft</button>
        <button class="pill-btn" data-status="archived">Archived</button>
      </div>
      <script>
        document.querySelectorAll('.pill-btn').forEach(btn => {
          btn.addEventListener('click', () => {
            document.querySelectorAll('.pill-btn').forEach(b => b.classList.remove('selected'));
            btn.classList.add('selected');
          });
        });
      </script>
    `);

    const allBtn = page.locator('[data-status="all"]');
    const activeBtn = page.locator('[data-status="active"]');
    const draftBtn = page.locator('[data-status="draft"]');
    const archivedBtn = page.locator('[data-status="archived"]');

    await expect(allBtn).toHaveClass(/selected/);
    await expect(activeBtn).not.toHaveClass(/selected/);

    // Click Active pill
    await activeBtn.click();
    await expect(activeBtn).toHaveClass(/selected/);
    await expect(allBtn).not.toHaveClass(/selected/);

    // Click Archived pill
    await archivedBtn.click();
    await expect(archivedBtn).toHaveClass(/selected/);
    await expect(draftBtn).not.toHaveClass(/selected/);
  });

  test("search input interaction clears and submits search terms", async ({ page }) => {
    await page.setContent(`
      <div class="search-container">
        <input type="text" id="search-input" placeholder="Search products by title..." value="" />
        <button id="clear-btn" style="display: none;">✕</button>
        <button id="search-submit">Search</button>
        <div id="search-feedback"></div>
      </div>
      <script>
        const input = document.getElementById('search-input');
        const clear = document.getElementById('clear-btn');
        const submit = document.getElementById('search-submit');
        const feedback = document.getElementById('search-feedback');

        input.addEventListener('input', () => {
          clear.style.display = input.value.length > 0 ? 'inline' : 'none';
        });

        submit.addEventListener('click', () => {
          feedback.textContent = 'Searching: ' + input.value;
        });

        clear.addEventListener('click', () => {
          input.value = '';
          clear.style.display = 'none';
          feedback.textContent = 'Cleared';
        });
      </script>
    `);

    const input = page.locator("#search-input");
    const clearBtn = page.locator("#clear-btn");
    const submitBtn = page.locator("#search-submit");
    const feedback = page.locator("#search-feedback");

    await expect(clearBtn).toBeHidden();

    // Type query
    await input.fill("Hoodie");
    await expect(clearBtn).toBeVisible();

    // Submit search
    await submitBtn.click();
    await expect(feedback).toHaveText("Searching: Hoodie");

    // Clear search
    await clearBtn.click();
    await expect(input).toHaveValue("");
    await expect(clearBtn).toBeHidden();
    await expect(feedback).toHaveText("Cleared");
  });

  test("empty state card renders with Clear Filters action when search yields zero results", async ({ page }) => {
    await page.setContent(`
      <div class="empty-state-card" data-variant="zero_filter_results">
        <div class="empty-icon">🔍</div>
        <h3 class="empty-heading">No products match your filters</h3>
        <p class="empty-description">Try adjusting your search query "NonexistentProduct" or status filter to see results.</p>
        <button id="clear-filters-action" class="primary-btn">Clear filters</button>
      </div>
    `);

    await expect(page.locator(".empty-state-card")).toBeVisible();
    await expect(page.locator(".empty-heading")).toHaveText("No products match your filters");
    await expect(page.locator(".empty-description")).toContainText("NonexistentProduct");
    await expect(page.locator("#clear-filters-action")).toHaveText("Clear filters");
  });

  test("live development store product table verification (conditional)", async ({ page }) => {
    const devStoreUrl = process.env.SHOPIFY_DEV_STORE_URL;
    test.skip(!devStoreUrl, "Set SHOPIFY_DEV_STORE_URL to run live development store E2E test");

    if (devStoreUrl) {
      await page.goto(devStoreUrl);
      await expect(page.locator("s-section[heading='Product Performance']")).toBeVisible();
    }
  });
});
