<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Customer\Models\Customer;
use App\Domain\Router\Models\Router;
use App\Services\RouterOS\ResourceClient;
use App\Support\Access\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $this->ensureAuthorized($request);

        $orgId = $request->user()->organization_id;
        $status = $request->string('status')->toString() ?: 'all';
        $search = $request->string('search')->toString();

        $query = Customer::query()
            ->with(['router:id,name,host'])
            ->where('organization_id', $orgId)
            ->latest();

        if ($status === 'paid') {
            $query->where('billing_status', 'paid');
        } elseif ($status === 'unpaid') {
            $query->where('billing_status', 'unpaid');
        } elseif ($status === 'overdue') {
            $query->where('billing_status', 'overdue');
        } elseif ($status === 'isolated') {
            $query->where('is_isolated', true);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }

        $customers = $query->paginate(25)->withQueryString();
        $all = Customer::where('organization_id', $orgId)->get();

        $routers = Router::where('organization_id', $orgId)->select('id', 'name', 'host')->get();

        $stats = [
            'total' => $all->count(),
            'paid' => $all->where('billing_status', 'paid')->count(),
            'unpaid' => $all->where('billing_status', 'unpaid')->count(),
            'overdue' => $all->where('billing_status', 'overdue')->count(),
            'isolated' => $all->where('is_isolated', true)->count(),
            'total_revenue' => (float) $all->where('billing_status', 'paid')->sum('monthly_bill'),
            'potential_revenue' => (float) $all->sum('monthly_bill'),
        ];

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'stats' => $stats,
            'routers' => $routers,
            'filters' => [
                'status' => $status,
                'search' => $search,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureAuthorized($request);

        $data = $request->validate([
            'router_id' => ['nullable', 'exists:routers,id'],
            'service_type' => ['required', 'string', 'in:pppoe,hotspot,static'],
            'username' => ['required', 'string', 'max:64'],
            'customer_name' => ['required', 'string', 'max:128'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'monthly_bill' => ['required', 'numeric', 'min:0'],
            'due_day' => ['required', 'integer', 'between:1,31'],
            'billing_status' => ['required', 'string', 'in:paid,unpaid,overdue'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $data['organization_id'] = $request->user()->organization_id;
        Customer::create($data);

        return back()->with('success', "Pelanggan '{$data['customer_name']}' berhasil ditambahkan.");
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->ensureAuthorized($request);
        abort_unless($customer->organization_id === $request->user()->organization_id, 403);

        $data = $request->validate([
            'router_id' => ['nullable', 'exists:routers,id'],
            'service_type' => ['required', 'string', 'in:pppoe,hotspot,static'],
            'username' => ['required', 'string', 'max:64'],
            'customer_name' => ['required', 'string', 'max:128'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'monthly_bill' => ['required', 'numeric', 'min:0'],
            'due_day' => ['required', 'integer', 'between:1,31'],
            'billing_status' => ['required', 'string', 'in:paid,unpaid,overdue'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $customer->update($data);

        return back()->with('success', "Data pelanggan '{$customer->customer_name}' berhasil diperbarui.");
    }

    public function destroy(Request $request, Customer $customer): RedirectResponse
    {
        $this->ensureAuthorized($request);
        abort_unless($customer->organization_id === $request->user()->organization_id, 403);

        $name = $customer->customer_name;
        $customer->delete();

        return back()->with('success', "Pelanggan '{$name}' telah dihapus.");
    }

    public function markPaid(Request $request, Customer $customer): RedirectResponse
    {
        $this->ensureAuthorized($request);
        abort_unless($customer->organization_id === $request->user()->organization_id, 403);

        $customer->update([
            'billing_status' => 'paid',
            'last_paid_at' => now(),
            'is_isolated' => false,
        ]);

        return back()->with('success', "Pembayaran '{$customer->customer_name}' berhasil dicatat LUNAS.");
    }

    public function toggleIsolir(Request $request, Customer $customer, ResourceClient $client): RedirectResponse
    {
        $this->ensureAuthorized($request);
        abort_unless($customer->organization_id === $request->user()->organization_id, 403);

        $newIsolated = ! $customer->is_isolated;
        $customer->update(['is_isolated' => $newIsolated]);

        // If customer is tied to a router and is PPPoE, attempt to modify profile on MikroTik
        if ($customer->router_id && $customer->service_type === 'pppoe') {
            try {
                $router = Router::find($customer->router_id);
                if ($router) {
                    $targetProfile = $newIsolated ? 'isolir' : 'default';

                    // Update PPP secret profile
                    $secrets = $client->list($router, '/ppp/secret', userId: $request->user()->id);
                    $secret = collect($secrets)->firstWhere('name', $customer->username);

                    if ($secret && isset($secret['.id'])) {
                        $client->set($router, '/ppp/secret', $secret['.id'], [
                            'profile' => $targetProfile,
                        ], $request->user()->id);

                        // Kick active session if isolating
                        if ($newIsolated) {
                            $actives = $client->list($router, '/ppp/active', userId: $request->user()->id);
                            $active = collect($actives)->firstWhere('name', $customer->username);
                            if ($active && isset($active['.id'])) {
                                $client->remove($router, '/ppp/active', $active['.id'], $request->user()->id);
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                // Device may be unreachable, state is saved in DB
            }
        }

        $statusStr = $newIsolated ? 'TERISOLIR' : 'AKTIF KEMBALI';

        return back()->with('success', "Status pelanggan '{$customer->customer_name}' diubah menjadi {$statusStr}.");
    }

    private function ensureAuthorized(Request $request): void
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $isAllowed = $user->isSuperAdmin()
            || $user->hasRole([
                Permissions::ROLE_SUPER_ADMIN,
                Permissions::ROLE_ORG_ADMIN,
                Permissions::ROLE_NETWORK_ADMIN,
                Permissions::ROLE_NETWORK_OPERATOR,
            ]);

        if (! $isAllowed) {
            abort(403, 'Akses ditolak: Anda tidak memiliki izin untuk mengelola data pelanggan.');
        }
    }
}
