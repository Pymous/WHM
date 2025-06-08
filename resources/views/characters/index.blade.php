@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-10">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">My EVE Characters</h4>
                        <a href="{{ route('characters.add') }}" class="btn btn-primary">Add Character</a>
                    </div>

                    <div class="card-body">
                        @if (session('success'))
                            <div class="alert alert-success" role="alert">
                                {{ session('success') }}
                            </div>
                        @endif

                        @if (session('error'))
                            <div class="alert alert-danger" role="alert">
                                {{ session('error') }}
                            </div>
                        @endif

                        @if ($characters->isEmpty())
                            <div class="text-center py-4">
                                <p>You haven't added any EVE characters yet.</p>
                                <a href="{{ route('characters.add') }}" class="btn btn-primary">Add Your First Character</a>
                            </div>
                        @else
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Character</th>
                                            <th>Character ID</th>
                                            <th>Status</th>
                                            <th>Token Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($characters as $character)
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <img src="https://images.evetech.net/characters/{{ $character->character_id }}/portrait?size=64"
                                                            alt="{{ $character->name }}" class="me-3"
                                                            style="width: 40px; height: 40px; border-radius: 50%;">
                                                        <div>
                                                            <div>{{ $character->name }}</div>
                                                            @if ($character->is_primary)
                                                                <span class="badge bg-success">Primary</span>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>{{ $character->character_id }}</td>
                                                <td>
                                                    @if ($character->is_primary)
                                                        <span class="badge bg-success">Primary</span>
                                                    @else
                                                        <span class="badge bg-secondary">Secondary</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if ($character->isTokenExpired())
                                                        <span class="badge bg-danger">Expired</span>
                                                    @else
                                                        <span class="badge bg-success">Valid</span>
                                                        <small class="d-block text-muted">Expires:
                                                            {{ $character->esi_expires_at->diffForHumans() }}</small>
                                                        @if ($character->esi_scopes)
                                                            <button class="btn btn-sm btn-link p-0" type="button"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#scopesModal{{ $character->id }}">
                                                                View Scopes
                                                            </button>

                                                            <!-- Scopes Modal -->
                                                            <div class="modal fade" id="scopesModal{{ $character->id }}"
                                                                tabindex="-1"
                                                                aria-labelledby="scopesModalLabel{{ $character->id }}"
                                                                aria-hidden="true">
                                                                <div class="modal-dialog">
                                                                    <div class="modal-content">
                                                                        <div class="modal-header">
                                                                            <h5 class="modal-title"
                                                                                id="scopesModalLabel{{ $character->id }}">
                                                                                {{ $character->name }}'s Scopes</h5>
                                                                            <button type="button" class="btn-close"
                                                                                data-bs-dismiss="modal"
                                                                                aria-label="Close"></button>
                                                                        </div>
                                                                        <div class="modal-body">
                                                                            <ul class="list-group">
                                                                                @foreach (explode(' ', $character->esi_scopes) as $scope)
                                                                                    <li class="list-group-item">
                                                                                        {{ $scope }}</li>
                                                                                @endforeach
                                                                            </ul>
                                                                        </div>
                                                                        <div class="modal-footer">
                                                                            <button type="button" class="btn btn-secondary"
                                                                                data-bs-dismiss="modal">Close</button>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        @endif
                                                    @endif
                                                </td>
                                                <td>
                                                    <div class="btn-group" role="group">
                                                        @if (!$character->is_primary)
                                                            <form action="{{ route('characters.primary', $character) }}"
                                                                method="POST">
                                                                @csrf
                                                                <button type="submit"
                                                                    class="btn btn-sm btn-outline-primary">Set as
                                                                    Primary</button>
                                                            </form>
                                                        @endif
                                                        <form action="{{ route('characters.destroy', $character) }}"
                                                            method="POST"
                                                            onsubmit="return confirm('Are you sure you want to remove this character?');">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit"
                                                                class="btn btn-sm btn-outline-danger">Remove</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
