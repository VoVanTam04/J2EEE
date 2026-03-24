package J2EE.bai4.service;

import J2EE.bai4.dto.CartItem;
import org.springframework.stereotype.Service;
import org.springframework.web.context.annotation.SessionScope;

import java.util.Collection;
import java.util.HashMap;
import java.util.Map;

@Service
@SessionScope
public class CartService {
    private Map<Integer, CartItem> map = new HashMap<>();

    public void add(CartItem item) {
        CartItem existedItem = map.get(item.getProductId());
        if (existedItem != null) {
            existedItem.setQuantity(item.getQuantity() + existedItem.getQuantity());
        } else {
            map.put(item.getProductId(), item);
        }
    }

    public void remove(int productId) {
        map.remove(productId);
    }

    public void update(int productId, int quantity) {
        CartItem existedItem = map.get(productId);
        if (existedItem != null) {
            existedItem.setQuantity(quantity);
        }
    }

    public void clear() {
        map.clear();
    }

    public Collection<CartItem> getItems() {
        return map.values();
    }

    public int getCount() {
        return map.values().stream().mapToInt(CartItem::getQuantity).sum();
    }

    public Long getAmount() {
        if (map.isEmpty()) return 0L;
        return map.values().stream().mapToLong(CartItem::getAmount).sum();
    }
}
