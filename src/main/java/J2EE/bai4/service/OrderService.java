package J2EE.bai4.service;

import J2EE.bai4.dto.OrderItemRequest;
import J2EE.bai4.model.*;
import J2EE.bai4.repository.AccountRepository;
import J2EE.bai4.repository.OrderRepository;
import J2EE.bai4.repository.ProductRepository;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.util.List;

@Service
public class OrderService {

    private final OrderRepository orderRepository;
    private final AccountRepository accountRepository;
    private final ProductRepository productRepository;

    public OrderService(OrderRepository orderRepository,
                        AccountRepository accountRepository,
                        ProductRepository productRepository) {
        this.orderRepository = orderRepository;
        this.accountRepository = accountRepository;
        this.productRepository = productRepository;
    }

    public List<Order> findByAccountUsername(String username) {
        Account account = accountRepository.findByUsername(username)
                .orElseThrow(() -> new IllegalArgumentException("Tài khoản không tồn tại"));
        return orderRepository.findByAccountIdOrderByOrderDateDesc(account.getId());
    }

    public Order getById(Integer id) {
        return orderRepository.findById(id).orElse(null);
    }

    @Transactional
    public Order createOrder(String username, List<OrderItemRequest> itemRequests) {
        Account account = accountRepository.findByUsername(username)
                .orElseThrow(() -> new IllegalArgumentException("Tài khoản không tồn tại"));

        Order order = new Order();
        order.setAccount(account);
        order.setStatus("PENDING");
        order.setTotalAmount(0L);

        long totalAmount = 0L;
        for (OrderItemRequest req : itemRequests) {
            if (req.getQuantity() == null || req.getQuantity() < 1) {
                continue;
            }
            Product product = productRepository.findById(req.getProductId()).orElse(null);
            if (product == null) {
                continue;
            }
            OrderItem item = new OrderItem();
            item.setOrder(order);
            item.setProduct(product);
            item.setQuantity(req.getQuantity());
            item.setPrice(product.getPrice());
            order.getItems().add(item);
            totalAmount += product.getPrice() * req.getQuantity();
        }

        if (order.getItems().isEmpty()) {
            throw new IllegalArgumentException("Đơn hàng phải có ít nhất một sản phẩm");
        }

        order.setTotalAmount(totalAmount);
        return orderRepository.save(order);
    }
}
