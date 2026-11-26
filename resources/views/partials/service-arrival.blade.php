<form id="llegada-{{ $subject->id }}" method="POST" action="{{ route('arrival.decide', [$type, $subject->id]) }}" class="d-flex flex-wrap align-items-center gap-2">
    @csrf
    @method('PATCH')
    <span>¿Llegó {{ $subject->client_name }}?</span>
    <button type="submit" name="decision" value="llego" class="btn btn-sm btn-gold">Llegó · iniciar servicio</button>
    <button type="submit" name="decision" value="no_llego" class="btn btn-sm btn-outline-danger">No llegó</button>
</form>
