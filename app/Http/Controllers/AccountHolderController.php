<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexAccountHoldersRequest;
use App\Http\Requests\StoreAccountHolderRequest;
use App\Http\Requests\UploadAccountHoldersRequest;
use App\Http\Resources\AccountHolderResource;
use App\Models\AccountHolder;
use App\Services\AccountHolderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The creditor and PCG personnel lists share one shape and one set of endpoints: list (newest
 * first, searchable, filterable by unit), add one or several, and batch upload. Each concrete controller names its model.
 */
abstract class AccountHolderController extends Controller
{
    public function __construct(private readonly AccountHolderService $service) {}

    /** @return class-string<AccountHolder> */
    abstract protected function model(): string;

    public function index(IndexAccountHoldersRequest $request): AnonymousResourceCollection
    {
        $query = $this->model()::query()->with('creator')->latest('id');

        if ($request->filled('search')) {
            $query->search($request->string('search'));
        }

        if ($request->filled('unit')) {
            $query->where('unit', $request->string('unit'));
        }

        return AccountHolderResource::collection($query->paginate(50)->withQueryString());
    }

    public function store(StoreAccountHolderRequest $request): JsonResponse
    {
        $records = $this->service->add($request->user(), $this->model(), $request->records());

        return AccountHolderResource::collection($records->each->load('creator'))
            ->response()->setStatusCode(201);
    }

    public function upload(UploadAccountHoldersRequest $request): JsonResponse
    {
        $count = $this->service->upload($request->user(), $this->model(), $request->file('file'));

        return response()->json(['count' => $count], 201);
    }
}
