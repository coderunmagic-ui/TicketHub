-- All keys share a hash tag. Redis TIME is the authoritative lease clock.
-- Atomic global FIFO gate, token bucket, expiring permits, and single-use processing claims.
local waiting, deadlines, active, nonces, types, sequence, bucket, claims, metrics = unpack(KEYS)
local op, member, ticket, nonce, claim = ARGV[1], ARGV[2], ARGV[3], ARGV[4], ARGV[5]
local slots, rate, burst = tonumber(ARGV[6]), tonumber(ARGV[7]), tonumber(ARGV[8])
local permitMs, processMs, heartbeatMs, maxWaiters = tonumber(ARGV[9]), tonumber(ARGV[10]), tonumber(ARGV[11]), tonumber(ARGV[12])
local stamp=redis.call('TIME')
local now=tonumber(stamp[1])*1000+math.floor(tonumber(stamp[2])/1000)
local function forget(id)
    redis.call('ZREM',waiting,id); redis.call('ZREM',deadlines,id); redis.call('ZREM',active,id)
    redis.call('HDEL',nonces,id); redis.call('HDEL',types,id); redis.call('HDEL',claims,id)
end
-- Bounded work per invocation prevents a large abandoned queue from blocking Redis.
for _,key in ipairs({deadlines,active}) do
    for _,id in ipairs(redis.call('ZRANGEBYSCORE',key,'-inf',now,'LIMIT',0,200)) do
        forget(id); redis.call('HINCRBY',metrics,'expired',1)
    end
end
local oldNonce=redis.call('HGET',nonces,member)
local function result(state, extra)
    local count=redis.call('ZCARD',active)
    local peak=math.max(count,tonumber(redis.call('HGET',metrics,'peak_active') or 0))
    redis.call('HSET',metrics,'peak_active',peak)
    local data={state=state,waiting=redis.call('ZCARD',waiting),active=count,peak_active=peak,server_ms=now}
    if extra then for k,v in pairs(extra) do data[k]=v end end
    return cjson.encode(data)
end
if op=='join' then
    if oldNonce and redis.call('HGET',types,member)~=ticket then return result('conflict') end
    if not oldNonce then
        if redis.call('ZCARD',waiting)+redis.call('ZCARD',active)>=maxWaiters then return result('full') end
        redis.call('HSET',nonces,member,nonce); redis.call('HSET',types,member,ticket)
        redis.call('ZADD',waiting,redis.call('INCR',sequence),member)
        redis.call('ZADD',deadlines,now+heartbeatMs,member)
        redis.call('HINCRBY',metrics,'joined',1)
        oldNonce=nonce
    end
elseif op~='metrics' then
    if not oldNonce or oldNonce~=nonce or redis.call('HGET',types,member)~=ticket then return result('gone') end
end
if op=='release' then
    if redis.call('HGET',claims,member)~=claim then return result('stale_claim') end
    forget(member); redis.call('HINCRBY',metrics,'finished',1)
elseif op=='leave' then
    if redis.call('HEXISTS',claims,member)==1 then return result('processing') end
    forget(member)
elseif op=='status' or op=='join' then
    if redis.call('ZSCORE',waiting,member) then redis.call('ZADD',deadlines,now+heartbeatMs,member) end
end
local last=tonumber(redis.call('HGET',bucket,'at') or now)
local tokens=math.min(burst,tonumber(redis.call('HGET',bucket,'tokens') or burst)+math.max(0,now-last)*rate/1000)
local available=math.min(slots-redis.call('ZCARD',active),math.floor(tokens),200)
local head={}
if available>0 then head=redis.call('ZRANGE',waiting,0,available-1) end
if available>0 then
    for _,id in ipairs(head) do
        local deadline=tonumber(redis.call('ZSCORE',deadlines,id) or 0)
        if deadline<=now then forget(id)
        else
            redis.call('ZREM',waiting,id); redis.call('ZREM',deadlines,id)
            redis.call('ZADD',active,now+permitMs,id); tokens=tokens-1
            redis.call('HINCRBY',metrics,'admitted',1)
        end
    end
end
redis.call('HSET',bucket,'at',now,'tokens',tokens)
for _,key in ipairs(KEYS) do redis.call('EXPIRE',key,86400) end
if op=='metrics' then
    return result('metrics',{joined=tonumber(redis.call('HGET',metrics,'joined') or 0),admitted=tonumber(redis.call('HGET',metrics,'admitted') or 0),expired=tonumber(redis.call('HGET',metrics,'expired') or 0),finished=tonumber(redis.call('HGET',metrics,'finished') or 0),tokens=math.floor(tokens)})
end
if op=='release' or op=='leave' then return result('left') end
local expires=tonumber(redis.call('ZSCORE',active,member) or 0)
if expires>now then
    if op=='claim' then
        if redis.call('HEXISTS',claims,member)==1 then return result('processing') end
        redis.call('HSET',claims,member,claim); expires=now+processMs
        redis.call('ZADD',active,expires,member)
        return result('claimed',{expires_ms=expires})
    end
    if redis.call('HEXISTS',claims,member)==1 then return result('processing') end
    return result('admitted',{expires_ms=expires,nonce=oldNonce})
end
local rank=redis.call('ZRANK',waiting,member)
if rank then return result('waiting',{position=rank+1,nonce=oldNonce}) end
return result('gone')
